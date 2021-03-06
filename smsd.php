<?php
require_once('settings.php');
declare(ticks = 1);
class dinstarsms {
    public $state = array();
    public $prevstate = array();
    private $debug = false;

    private $run = true;
    function sig_handler($signo)
    {

         switch ($signo) {
             case SIGTERM:
                 // handle shutdown tasks
                 $this->run = false;
                 socket_close($this->client);
                 socket_close($this->tcp_socket);
                 exit;
                 break;
             case SIGHUP:
                 // handle restart tasks
                 break;
             case SIGUSR1:
                 echo "Caught SIGUSR1...\n";
                 break;
             default:
                 // handle all other signals
         }

    }
    function logg($text){
        echo date("Y-m-d H:i:s")." : ".$text."\n";
    }
    function parse( $pkt ) {
        $header = array(
            'len' => unpack('N',substr($pkt,0,4)),
            'id' => array(
                'mac' => unpack('H*',substr($pkt,4,6)),
                'time' => unpack('N',substr($pkt,12,4)),
                'serial' => unpack('N',substr($pkt,16,4)),
            ),
            'type' => unpack('n',substr($pkt,20,2)),
            'flag' => unpack('n',substr($pkt,22,2)),
        );

        $header = array(
            'len' => $header['len'][1],
            'id' => array(
                'mac' => $header['id']['mac'][1],
                'time' => $header['id']['time'][1],
                'serial' => $header['id']['serial'][1],
            ),
            'type' => $header['type'][1],
            'flag' => $header['flag'][1],
        );

        switch($header['type']) {
            case 5:
                $body = array(
                    'number' => substr($pkt,24,24),
                    'type' => ord(substr($pkt,48,1)),
                    'port' => ord(substr($pkt,49,1)),
                    'timestamp' => substr($pkt,50,15),
                    'timezone' => ord(substr($pkt,65,1)),
                    'encoding' => ord(substr($pkt,66,1)),
                    'len' => unpack('n',substr($pkt,67,2)),
                    'content' => substr($pkt,69),
                );
                $body['len'] = $body['len'][1];
                if ( $body['encoding'] == 1 ) {
                    $body['content'] = utf8_encode($body['content']);
                    $body['content'] = str_replace("\0", "", $body['content']);
                }
                $this->logg("new SMS from: ".$body['number']." content:".$body['content']);
                if($this->debug)
                    print_r($body);
                $this->email($body['number'],$body['content']);
                return $this->send($header,6,chr(0));
                break;
            case 7:
                $body = array(
                    'count' => ord(substr($pkt,24,1))
                );
                for($i=0;$i<$body['count'];$i++) {
                    $body[$i] = ord(substr($pkt,25+$i,1));
                }

                $this->state = $body;

                if ( $this->prevstate != $this->state ) {
                    $this->prevstate = $this->state;
                    if($this->debug)
                        print_r($this->state);
                }
                if($this->debug){
                    print_r($header);
                    echo "\n";
                    print_r($body);
                }

                return $this->send($header,8,chr(0));
                break;
            case 15:
                //respond to login
                return $this->send($header,16,chr(0));
                break;
            default:
                if($this->debug){
                    $body = array(unpack('H*',substr($pkt,24)));
                    print_r($header);
                    print_r($body);
                }
                return true;
        }
    }

    function send($header,$type,$body) {
        $pkt = pack('N',strlen($body));
        $pkt .= pack('H*',$header['id']['mac'])."\x00\x00";
        $pkt .= pack('N',$header['id']['time']);
        $pkt .= pack('N',$header['id']['serial']);
        $pkt .= pack('n',$type);
        $pkt .= pack('n',$header['flag']);
        $pkt .= $body;

        if($this->debug)
            print_r("OUT ". $this->hex2ascii($pkt)."\n");

        if ( !$bytes = socket_write($this->client,$pkt) )
            return false;
        if($this->debug)
            echo "Sent $bytes bytes of ".strlen($pkt)."\n";
        return true;
    }
    function email($from,$text){
        $from = trim($from);
        $headers = "From: $from@sms.jonaz.net\r\n";
        $headers .= "Reply-To: $from@sms.jonaz.net\r\n";
        $headers .= "X-Mailer: PHP/".phpversion()."\r\n";
        $headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
        mail(settings::mailto,'New sms from '.$from,$text,$headers);
    }

    function __construct(){

        pcntl_signal(SIGTERM,array(&$this,"sig_handler")); 

        $this->tcp_socket = socket_create(AF_INET, SOCK_STREAM, getprotobyname('tcp'));
        socket_set_option($this->tcp_socket, SOL_SOCKET, SO_REUSEADDR, 1);
        if(!socket_bind($this->tcp_socket,'0.0.0.0',settings::port))
            die('failed to bind');
        socket_getsockname($this->tcp_socket,$ip,$p);
        socket_listen($this->tcp_socket,100);

        //socket_set_nonblock($this->tcp_socket);
        while( $this->run === true ) {
            $this->logg("waiting for client to connect");
            $this->client = socket_accept($this->tcp_socket);  
            socket_set_nonblock($this->client);
            $this->logg( "client connected");
            $buff = '';
            $start = time();
            while($this->run === true){


                if($start < time()-45){
                    $header = array(
                        'len' =>0, 
                        'id' => array(
                            'mac' => '001fd6c706a1',
                            'time' => time(),
                            'serial' => 0,
                        ),
                        'flag' => 0,
                    );  
                    if(!$this->send($header,0,'')){
                        $this->logg( "sendbreak");
                        break;
                    }
                    $start = time();
                }
                $line = socket_read($this->client, 1024); 

                if(strlen($line) > 0 )
                    $buff .= $line;

                while ( $buff ) {
                    // Body length
                    $len = ord(substr($buff,0,1))*256*256*256;
                    $len += ord(substr($buff,1,1))*256*256;
                    $len += ord(substr($buff,2,1))*256;
                    $len += ord(substr($buff,3,1));

                    // Add header
                    $len += 24;

                    $pkt = substr($buff,0,$len);

                    if( strlen($pkt) == $len && strlen($pkt) > 23 ) {
                        $buff = substr($buff,$len);

                        if($this->debug){
                            print_r("len: ".strlen($pkt)."\n");
                            print_r( "IN ".$this->hex2ascii($pkt)."\n");
                        }
                        if(!$this->parse($pkt)){
                            $this->logg("parsebreak");
                            break 2;
                        }
                    } else {
                        //print_r( "HALF ".$this->hex2ascii($pkt)." |  ".$this->hex2ascii($buff)."\n");
                        break;
                    }
                }
               usleep(100000);
            }
            $this->logg("client disconnected");
            socket_close($this->client);
        }

        socket_close($this->client);
        socket_close($this->tcp_socket);
    }

    function hex2ascii($str)
    {
        $tmp = unpack("H*",$str);
        return $tmp[1];
        $p = '';
        for ($i=0; $i < strlen($str); $i=$i+1)
        {
            $tmp = dechex(ord(substr($str, $i, 1)));
            if(strlen($tmp) === 1 )
                $tmp = '0'.$tmp;
            $p .= ' '.$tmp;
        }
        return trim($p);
    }

}

$t = new dinstarsms();

?>
