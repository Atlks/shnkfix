<?php


namespace app\api\library;


class OtherLogin
{
    /**
     * curl请求
     * @param string $url
     * @param null $data
     * @param array $header
     * @return bool|string
     */
    protected function http_request($url = '', $data = null, $header = [])
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_HEADER, $header);
        if (!empty($data)) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            curl_setopt($curl,CURLOPT_TIMEOUT,180);
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
    }

    /**
     * 第八区登录
     * @param string $account
     * @param string $password
     * @return array
     */
    public function dbqLogin($account='',$password=''){
        $url = 'http://dibaqu.com/api/user/userAuth';
        $token = '19b97fcc68143381b403035c7e5d07a7';
        $username = trim($account);
        $password = trim($password);
        $params = [
            'username'=>$username,
            'password'=>$password,
            'sign'=>md5($username.$password.$token)
        ];
        $result = $this->http_request($url,$params);
        $result = json_decode($result,true);
        if($result['code']==200){
            return [
                'code'=>200,
                'nickname'=>$result['data']['nickname'],
                'uid'     =>$result['data']['uid']
            ];
        }else{
            return ['code'=>0,'msg'=>$result['msg']];
        }
    }

}