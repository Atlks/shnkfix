<?php


namespace app\common\library;


class WyDun
{
    protected $captcha_id='abe113943848407087823bbd0d573369';

    protected $secretId='a2fe727a55d633e785278598ed941f33';

    protected $secret_key='0ab09190b3946ceb9605c69fced6c75d';

    protected $version="v2";

    protected $url='http://c.dun.163yun.com/api/v2/verify';

    /**
     * 二次验证
     * @param $validate
     * @param $user
     * @return bool|mixed
     */
    public function verify($validate, $user) {
        $params = array();
        $params["captchaId"] = $this->captcha_id;
        $params["validate"] = $validate;
        $params["user"] = $user;
        // 公共参数
        $params["secretId"] = $this->secretId;
        $params["version"] = $this->version;
        $params["timestamp"] = sprintf("%d", round(microtime(true)*1000));
        $params["nonce"] = sprintf("%d", rand()); // random int
        $params["signature"] = $this->sign($this->secret_key, $params);
        $result = $this->send_http_request($params);
        return array_key_exists('result', $result) ? $result['result'] : false;
    }

    /**
     * 签名
     * @param $secret_key
     * @param $params
     * @return string
     */
    private function sign($secret_key, $params){
        ksort($params);
        $buff="";
        foreach($params as $key=>$value){
            $buff .=$key;
            $buff .=$value;
        }
        $buff .= $secret_key;
        return md5($buff);
    }

    /**
     * http
     * @param $params
     * @return array|mixed
     */
    private function send_http_request($params){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        /*
         * Returns TRUE on success or FALSE on failure.
         * However, if the CURLOPT_RETURNTRANSFER option is set, it will return the result on success, FALSE on failure.
         */
        $result = curl_exec($ch);
        if(curl_errno($ch)){
            $msg = curl_error($ch);
            curl_close($ch);
            return array("error"=>500, "msg"=>$msg, "result"=>false);
        }else{
            curl_close($ch);
            return json_decode($result, true);
        }
    }


}