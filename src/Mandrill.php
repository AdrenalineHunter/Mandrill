<?php

use Mandrill\Templates;
use Mandrill\Metadata;
use Mandrill\Senders;
use Mandrill\Webhooks;
use Mandrill\Urls;
use Mandrill\Subaccounts;
use Mandrill\Internal;
use Mandrill\Ips;
use Mandrill\Whitelists;
use Mandrill\Messages;
use Mandrill\Tags;
use Mandrill\Inbound;
use Mandrill\Rejects;
use Mandrill\Users;
use Mandrill\Exports;
use Mandrill\MandrillError;
use Mandrill\MandrillHttpError;

class Mandrill {

    public $apikey;
    public $ch;
    public $root = 'https://mandrillapp.com/api/1.0';
    public $debug = false;

    public static $error_map = array(
        "ValidationError" => "MandrillValidationError",
        "Invalid_Key" => "MandrillInvalidKey",
        "PaymentRequired" => "MandrillPaymentRequired",
        "Unknown_Subaccount" => "MandrillUnknownSubaccount",
        "Unknown_Template" => "MandrillUnknownTemplate",
        "ServiceUnavailable" => "MandrillServiceUnavailable",
        "Unknown_Message" => "MandrillUnknownMessage",
        "Invalid_Tag_Name" => "MandrillInvalidTagName",
        "Invalid_Reject" => "MandrillInvalidReject",
        "Unknown_Sender" => "MandrillUnknownSender",
        "Unknown_Url" => "MandrillUnknownUrl",
        "Unknown_TrackingDomain" => "MandrillUnknownTrackingDomain",
        "Invalid_Template" => "MandrillInvalidTemplate",
        "Unknown_Webhook" => "MandrillUnknownWebhook",
        "Unknown_InboundDomain" => "MandrillUnknownInboundDomain",
        "Unknown_InboundRoute" => "MandrillUnknownInboundRoute",
        "Unknown_Export" => "MandrillUnknownExport",
        "IP_ProvisionLimit" => "MandrillIPProvisionLimit",
        "Unknown_Pool" => "MandrillUnknownPool",
        "NoSendingHistory" => "MandrillNoSendingHistory",
        "PoorReputation" => "MandrillPoorReputation",
        "Unknown_IP" => "MandrillUnknownIP",
        "Invalid_EmptyDefaultPool" => "MandrillInvalidEmptyDefaultPool",
        "Invalid_DeleteDefaultPool" => "MandrillInvalidDeleteDefaultPool",
        "Invalid_DeleteNonEmptyPool" => "MandrillInvalidDeleteNonEmptyPool",
        "Invalid_CustomDNS" => "MandrillInvalidCustomDNS",
        "Invalid_CustomDNSPending" => "MandrillInvalidCustomDNSPending",
        "Metadata_FieldLimit" => "MandrillMetadataFieldLimit",
        "Unknown_MetadataField" => "MandrillUnknownMetadataField"
    );

    public function __construct($apikey=null) {
        if(!$apikey) $apikey = getenv('MANDRILL_APIKEY');
        if(!$apikey) $apikey = $this->readConfigs();
        if(!$apikey) throw new MandrillError('You must provide a Mandrill API key');
        $this->apikey = $apikey;

        $this->ch = curl_init();
        curl_setopt($this->ch, CURLOPT_USERAGENT, 'Mandrill-PHP/1.0.55');
        curl_setopt($this->ch, CURLOPT_POST, true);
        curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->ch, CURLOPT_HEADER, false);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($this->ch, CURLOPT_TIMEOUT, 600);

        $this->root = rtrim($this->root, '/') . '/';

        $this->templates = new Templates($this);
        $this->exports = new Exports($this);
        $this->users = new Users($this);
        $this->rejects = new Rejects($this);
        $this->inbound = new Inbound($this);
        $this->tags = new Tags($this);
        $this->messages = new Messages($this);
        $this->whitelists = new Whitelists($this);
        $this->ips = new Ips($this);
        $this->internal = new Internal($this);
        $this->subaccounts = new Subaccounts($this);
        $this->urls = new Urls($this);
        $this->webhooks = new Webhooks($this);
        $this->senders = new Senders($this);
        $this->metadata = new Metadata($this);
    }

    public function __destruct() {
        curl_close($this->ch);
    }

    public function call($url, $params) {
        $params['key'] = $this->apikey;
        $params = json_encode($params);
        $ch = $this->ch;

        curl_setopt($ch, CURLOPT_URL, $this->root . $url . '.json');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_VERBOSE, $this->debug);

        $start = microtime(true);
        $this->log('Call to ' . $this->root . $url . '.json: ' . $params);
        if($this->debug) {
            $curl_buffer = fopen('php://memory', 'w+');
            curl_setopt($ch, CURLOPT_STDERR, $curl_buffer);
        }

        $response_body = curl_exec($ch);
        $info = curl_getinfo($ch);
        $time = microtime(true) - $start;
        if($this->debug) {
            rewind($curl_buffer);
            $this->log(stream_get_contents($curl_buffer));
            fclose($curl_buffer);
        }
        $this->log('Completed in ' . number_format($time * 1000, 2) . 'ms');
        $this->log('Got response: ' . $response_body);

        if(curl_error($ch)) {
            throw new MandrillHttpError("API call to $url failed: " . curl_error($ch));
        }
        $result = json_decode($response_body, true);
        if($result === null) throw new MandrillError('We were unable to decode the JSON response from the Mandrill API: ' . $response_body);
        
        if(floor($info['http_code'] / 100) >= 4) {
            throw $this->castError($result);
        }

        return $result;
    }

    public function readConfigs() {
        $paths = array('~/.mandrill.key', '/etc/mandrill.key');
        foreach($paths as $path) {
            if(file_exists($path)) {
                $apikey = trim(file_get_contents($path));
                if($apikey) return $apikey;
            }
        }
        return false;
    }

    public function castError($result) {
        if($result['status'] !== 'error' || !$result['name']) throw new MandrillError('We received an unexpected error: ' . json_encode($result));

        $class = (isset(self::$error_map[$result['name']])) ? self::$error_map[$result['name']] : 'MandrillError';
        return new $class($result['message'], $result['code']);
    }

    public function log($msg) {
        if($this->debug) error_log($msg);
    }
}
