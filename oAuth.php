<?php

class oAuth
{
    const CHPP_OAUTH_HOST = "https://chpp.hattrick.org";
    const CHPP_CONSUMER_KEY = 'xxx';
    const CHPP_CONSUMER_SECRET = 'xxx';
    var $_CHPP_REQUEST_TOKEN_URL;
    var $_CHPP_AUTHORIZE_URL;
    var $_CHPP_ACCESS_TOKEN_URL;
    var $_CHPP_CHECK_TOKEN_URL;
    var $_CHPP_INVALIDATE_TOKEN_URL;
    var $_CHPP_FILE_URL;

    function __construct() {
        $this->_CHPP_REQUEST_TOKEN_URL = self::CHPP_OAUTH_HOST . "/oauth/request_token.ashx";
        $this->_CHPP_ACCESS_TOKEN_URL = self::CHPP_OAUTH_HOST . "/oauth/access_token.ashx";
        $this->_CHPP_AUTHORIZE_URL = self::CHPP_OAUTH_HOST . "/oauth/authorize.aspx";
        $this->_CHPP_CHECK_TOKEN_URL = self::CHPP_OAUTH_HOST . "/oauth/check_token.ashx";
        $this->_CHPP_INVALIDATE_TOKEN_URL = self::CHPP_OAUTH_HOST . "/oauth/invalidate_token.ashx";
        $this->_CHPP_FILE_URL = "http://chpp.hattrick.org/chppxml.ashx";
    }

    #region multirequest
    private function buildSignature($url, $params, $token = '', $method = 'GET')
    {
        $parts = array($method, $url, $this->buildHttpQuery($params));
        $parts = implode('&', $this->urlencodeRfc3986($parts));
        $key_parts = array(self::CHPP_CONSUMER_SECRET, $token);
        $key = implode('&', $this->urlencodeRfc3986($key_parts));
        $sign = base64_encode(hash_hmac('sha1', $parts, $key, true));
        return $sign;
    }

    private function buildHttpQuery($params)
    {
        if(!count($params))
        {
            return '';
        }
        $keys = $this->urlencodeRfc3986(array_keys($params));
        $values = $this->urlencodeRfc3986(array_values($params));
        $params = array_combine($keys, $values);
        uksort($params, 'strcmp');
        $pairs = array();
        foreach ($params as $parameter => $value)
        {
            if(is_array($value))
            {
                sort($value, SORT_STRING);
                foreach($value as $duplicate_value)
                {
                    $pairs[] = $parameter . '=' . $duplicate_value;
                }
            }
            else
            {
                $pairs[] = $parameter . '=' . $value;
            }
        }
        return implode('&', $pairs);
    }

    private function urlencodeRfc3986($input)
    {
        if(is_array($input))
        {
            return array_map(array(&$this, 'urlencodeRfc3986'), $input);
        }
        return str_replace('+', ' ', str_replace('%7E', '~', rawurlencode($input)));
    }

    private function buildOauthUrl($file, $params)
    {
        $url = $file."?";
        foreach ($params as $param => $value)
        {
            $url .= $param."=".$this->urlencodeRfc3986($value)."&";
        }
        return substr($url,0,-1);
    }

    public function prepareRequest($token, $params, $postParams = array())
    {
        $params = array_merge($params, array(
            'oauth_consumer_key' => self::CHPP_CONSUMER_KEY,
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => time(),
            'oauth_nonce' => md5(microtime().mt_rand()),
            'oauth_token' => $token->token,
            'oauth_version'=> '1.0'
        ));
        $signature = $this->buildSignature($this->_CHPP_FILE_URL, array_merge($params, $postParams), $token->verifier, ($postParams == array() ? 'GET' : 'POST'));
        $params['oauth_signature'] = $signature;
        uksort($params, 'strcmp');
        $url = $this->buildOauthUrl($this->_CHPP_FILE_URL, $params);

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        if(count($postParams))
        {
            curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postParams));
        }
        else
        {
            curl_setopt($curl, CURLOPT_POST, false);
        }
        return $curl;
    }

    public function executePreparedRequests($curls_with_key) {
        // http://www.onlineaspect.com/2009/01/26/how-to-use-curl_multi-without-blocking/#idc-cover
        if (empty($curls_with_key)) return array();

        $curls = array();
        $flip = array();
        foreach ($curls_with_key as $key => $curl) {
            $curls[] = $curl;
            $flip[$curl] = $key;
        }
        // make sure the rolling window isn't greater than the # of urls
        $size = count($curls);
        $rolling_window = 5;
        $rolling_window = ($size < $rolling_window) ? $size : $rolling_window;

        $master = curl_multi_init();

        // start the first batch of requests
        for ($i = 0; $i < $rolling_window; $i++)
            curl_multi_add_handle($master, $curls[$i]);

        $output = array();

        do {
            while(($execrun = curl_multi_exec($master, $running)) == CURLM_CALL_MULTI_PERFORM) {
                usleep(50000);
            };
            if ($execrun != CURLM_OK)
                break;
            // a request was just completed -- find out which one
            while ($done = curl_multi_info_read($master)) {
                $info = curl_getinfo($done['handle']);
                if ($info['http_code'] == 200)  {
                    $output[$flip[$done['handle']]] = curl_multi_getcontent($done['handle']);

                    // start a new request (it's important to do this before removing the old one)
                    if ($i < $size)
                        curl_multi_add_handle($master, $curls[$i++]); // increment i

                    // remove the curl handle that just completed
                    curl_multi_remove_handle($master, $done['handle']);
                } else {
                    // request failed.  add error handling.
                }
                usleep(50000);
            }
        } while ($running);

        curl_multi_close($master);
        return $output;
    }
    #endregion

}


// example usage:

$handles = array();
if (!empty($players)) {
    foreach ($players as $player) {
        $handle = $oAuth->prepareRequest($token, array('file' => 'transfersplayer', 'playerID' => $player->HtId));
        $handles[$player->HtId] = $handle;
    }
    $transfersplayerXmls = $oAuth->executePreparedRequests($handles);
    foreach ($transfersplayerXmls as $xml) {
        // ACTION with the xml files, use simpleXML or PHT do read it.
    }
}