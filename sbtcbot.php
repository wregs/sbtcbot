<?php
/*****************************
 * Slack BOT with a functionality to handle basic request for cryptopay.me
 * 
 * Bot is based on php-slack-bot https://github.com/jclg/php-slack-bot
 * 
 * The following is code is free to use as long as the original contribution of
 * its author is mentioned in any form
 * 
 * by Pavel Vavilov
 * 
 * */
 
require 'vendor/autoload.php';
use PhpSlackBot\Bot;

static $bot_token="SLACK_BOT_TOKEN";
static $usermapfile="SLACK_TO_CRYPTOPAY_MAP_FILE";

// class to dump messages to console
class CryptoLogger{
	public static function log_msg($message){
		print(date("c")." $message\n");
	}
}

// Custom command
class BtcCommand extends \PhpSlackBot\Command\BaseCommand {

	private $initiator;
	
    protected function configure() {
        $this->setName('btc');
    }
    // function uses web api for attachments addition, sends messages to im chat
    protected function sendAttachment($channel, $username, $message, $attachment,$token) {
		$ch= curl_init("https://slack.com/api/chat.postMessage");
		$responce=array(
							"token"=>$token,
							"channel"=>$channel,
							"as_user"=>"true",
							"text"=>(!is_null($username) ? '<@'.$username.'> ' : '').$message,
							"attachments"=>"[$attachment]"
							);

        curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($responce));        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);        
       
        curl_close($ch);
        
    }
	
    protected function execute($message, $context) {
		$args = $this->getArgs($message);
		$command = isset($args[1]) ? $args[1] : '';
		$this->initiator = $this->getCurrentUser();
		switch ($command) {
			case('wallet'):
				$this->viewWallet();
				break;
			case('new'):
				$this->newInvoice($args);
				break;
			case('rates'):
				$this->viewRates();
				break;
			case('view'):
				$this->viewInvoices($args);
				break;
			default:
				$this->sendHelp();				
		}
       
    }
    
    private function getArgs($message) {
        $args = array();
        if (isset($message['text'])) {
            $args = array_values(array_filter(explode(' ', $message['text'])));
        }
        $commandName = $this->getName();
        // Remove args which are before the command name
        $finalArgs = array();
        $remove = true;
        foreach ($args as $arg) {
            if ($commandName == $arg) {
                $remove = false;
            }
            if (!$remove) {
                $finalArgs[] = $arg;
            }
        }
        return $finalArgs;
    }
    private function sendHelp(){
		
		
		$help_commands="use:\n".$this->getName()." wallet - View btc wallet balance\n".
				$this->getName()." new <desc> <amount> - New btc invoice\n".
				$this->getName()." view <desc> <status>- view created invoices by desc and status\n".
				$this->getName()." rates - BTC rates";
		$this->send($this->getCurrentChannel(), $this->initiator,
                        $help_commands);
		
	}
	// function processes view wallet command
	// connects to cryptopay.me server to view user balance, user credentials are read from file slack user<-->cryptopay token
	private function viewWallet(){
		global $usermapfile;
		$json_usermap=file_get_contents($usermapfile);
        $usermap=json_decode($json_usermap,true);
        
        $username=$this->getUsernameFromUserId($this->initiator);
        
        $btc_akey=$usermap[$username];
        if(strlen($btc_akey)==0){
			CryptoLogger::log_msg("Username: $username is not mapped to cryptopay.me token");
			$this->send($this->getCurrentChannel(), $this->initiator,
                        "Your username is not mapped to cryptopay.me token!");
			return null;
		}
			
        $ch=curl_init("https://cryptopay.me/api/v1/balance?api_key=$btc_akey");       
        
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        
        $balance_json_responce=curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
		CryptoLogger::log_msg("Request balance for user: $username and $btc_akey: status code: $status_code");
        
		if($status_code!="200" && $status_code!="201"){
			$this->send($this->getCurrentChannel(), $this->initiator,
                        "Oops. Bad responce from cryptopay.me server - $status_code!");
            return null;
		}
        $balance=json_decode($balance_json_responce,true);
        $balance_msg="";
        
        if(strlen($balance["EUR"])>0) $balance_msg="EUR: ".$balance["EUR"];
        if(strlen($balance["USD"])>0) $balance_msg=$balance_msg."\nUSD: ".$balance["USD"];
        if(strlen($balance["GBP"])>0) $balance_msg=$balance_msg."\nGBP: ".$balance["GBP"];
        if(strlen($balance["BTC"])>0) $balance_msg=$balance_msg."\nBTC: ".$balance["BTC"];
        
		$this->send($this->getCurrentChannel(), $this->initiator,"Wallet balance:\n".$balance_msg);
	}
	// function processes new invoices and sends request to cryptopay.me server
	private function newInvoice($args){
		global $usermapfile;
		
		if(count($args)<4){$this->sendHelp();return null;}
		
		$amount=floatval($args[count($args)-1]);
		
		if($amount==0) {
			$this->send($this->getCurrentChannel(), $this->initiator,
                        "Amount is a bad value, should be currency!");
			return null;
        }
       
        $desc_array=array_slice($args,2,-1);
        $desc=implode(" ",$desc_array);        
        
		$json_usermap=file_get_contents($usermapfile);
        $usermap=json_decode($json_usermap,true);
        
        $username=$this->getUsernameFromUserId($this->initiator);
        
        $btc_akey=$usermap[$username];
        
        if(strlen($btc_akey)==0){
			$this->send($this->getCurrentChannel(), $this->initiator,
                        "Your username is not mapped to cryptopay.me acc!");
			return null;
		}
        $invoice_data_array=array("api_key"=>$btc_akey,"price"=>"$amount","currency"=>"BTC","description"=>$desc);
        
        $invoice_json_request=json_encode($invoice_data_array);
        
        $ch=curl_init("https://cryptopay.me/api/v1/invoices");       
        
        curl_setopt($ch,CURLOPT_POSTFIELDS,$invoice_json_request);
        curl_setopt($ch,CURLOPT_HTTPHEADER,array('Content-Type:application/json'));
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        
        $invoice_json_responce=curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        CryptoLogger::log_msg("Sent new invoice request: ". $invoice_json_request." return status code $status_code");
		CryptoLogger::log_msg("Recieved invoice responce: ".$invoice_json_responce);
		
		if($status_code!="200" && $status_code!="201"){
			$this->send($this->getCurrentChannel(), $this->initiator,
                        "Oops. Bad responce from cryptopay.me server - $status_code!");
            return null;
		}
		
		$invoice_data_array=json_decode($invoice_json_responce,true);
		
		$btc_address=$invoice_data_array["btc_address"];
		$btc_price=$invoice_data_array["btc_price"];
		$bitcoin_uri=$invoice_data_array["bitcoin_uri"];
		$qr_code="https://chart.googleapis.com/chart?chs=128x128&cht=qr&chl=%22".$bitcoin_uri."%22";
		
		global $bot_token;	
		
		$attachment=json_encode(array(
					"text"=>$bitcoin_uri,
					"image_url"=>$qr_code
					));
		$this->sendAttachment($this->getCurrentChannel(), $this->initiator,
                        "Waiting $btc_price BTC to $btc_address\n",$attachment,$bot_token);
		
	}
	// function processes view rates command
	private function viewRates(){		

        CryptoLogger::log_msg("Sent rates request");
        $rates_json_responce=file_get_contents("http://cryptopay.me/api/rates");
		
		$rates=json_decode($rates_json_responce,true);
		CryptoLogger::log_msg("Got rates responce: ".json_encode($rates));
		$this->send($this->getCurrentChannel(), $this->initiator,
                        "Exchange rates:".
                        "\nEUR: buy  ".$rates["BTCEUR"]["buy"]." | sell  ".$rates["BTCEUR"]["sell"].
                        "\nUSD: buy  ".$rates["BTCUSD"]["buy"]." | sell  ".$rates["BTCUSD"]["sell"].
                        "\nGBP: buy  ".$rates["BTCGBP"]["buy"]." | sell  ".$rates["BTCGBP"]["sell"]);
	}
	// function processes view invoices command
	private function viewInvoices($args){
		
		$invoices_per_page=20;		
		global $usermapfile;
		
		if(count($args)<4){$this->sendHelp();return null;}
			
		$preset_status=array("pending","paid","partpaid","confirmed","timeout","*");
		$status=$args[count($args)-1];
		
		$found_index=array_search($status,$preset_status);
		if($found_index===FALSE){
			$this->send($this->getCurrentChannel(), $this->initiator,
                        "Status code is bad. Use one (".implode(",",$preset_status).")");
			return null;
		}
		
		$desc_array=array_slice($args,2,-1);
        $desc=implode(" ",$desc_array);        
       
		$json_usermap=file_get_contents($usermapfile);
        $usermap=json_decode($json_usermap,true);
        
        $username=$this->getUsernameFromUserId($this->initiator);
        
        $btc_akey=$usermap[$username];
        if(strlen($btc_akey)==0){
			CryptoLogger::log_msg("Username: $username is not mapped to cryptopay.me token");
			$this->send($this->getCurrentChannel(), $this->initiator,
                        "Your username is not mapped to cryptopay.me token!");
			return null;
		}
			
		$req_page=1;
		$selected_invoices=array();
		do{	
        $ch=curl_init("https://cryptopay.me/api/v1/invoices?api_key=$btc_akey&page=$req_page&per_page=$invoices_per_page");       
        
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
        
        $invoices_json_responce=curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
		CryptoLogger::log_msg("Get invoices user: $username and $btc_akey: status code: $status_code");
        
		if($status_code!="200" && $status_code!="201"){
			$this->send($this->getCurrentChannel(), $this->initiator,
                        "Oops. Bad responce from cryptopay.me server - $status_code!");
            return null;
		}
		
        $invoices_responce=json_decode($invoices_json_responce,true);
        
        $current_page=(int)$invoices_responce["page"];
        $total_pages=(int)$invoices_responce["total_pages"];
        
        $invoices=$invoices_responce["invoices"];
        
        for($i=0;$i<count($invoices);$i++){
			if($desc!=$invoices[$i]["description"]&&$desc!="*") continue;
			if($status!=$invoices[$i]["status"]&&$status!="*") continue;
			
			$selected_invoices[]=$invoices[$i];
		}
        
        if($current_page<$total_pages) $req_page++;
        else break;
        
		}while(1);
		
		$out_msg="";
		for($i=0;$i<count($selected_invoices);$i++){			
			$out_msg=$out_msg.
					$selected_invoices[$i]["btc_address"]." | <".
					$selected_invoices[$i]["description"]."> | *".
					$selected_invoices[$i]["status"]."*\n";
		}
		$out_msg=trim($out_msg);
		if(strlen($out_msg)>0) $out_msg="\n".$out_msg;
		else $out_msg="No invoices found, sorry :(";
		$this->send($this->getCurrentChannel(), $this->initiator,$out_msg);
	}
	
	
}

$bot = new Bot();
$bot->setToken($bot_token); // Get your token here https://my.slack.com/services/new/bot
$bot->loadCommand(new BtcCommand());
$bot->run();
?>
