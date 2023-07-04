<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\library\IosPackage;
use app\common\library\Ip2Region;
use app\common\library\Oss;
use app\common\model\Area;
use app\common\model\Config;
use app\common\model\OssConfig;
use app\common\model\ProxyApp;
use app\common\model\ProxyAppUpdateLog;
use app\common\model\ProxyUser;
use app\common\model\ProxyUserDomain;
use app\common\model\Version;
use fast\Random;
use think\Db;
use think\Log;

/**
 * 公共接口
 */
class Common extends Api
{
    protected $noNeedLogin = [];
    protected $noNeedRight = '*';

    /**
     * 加载初始化
     *
     * @param string $version 版本号
     * @param string $lng     经度
     * @param string $lat     纬度
     */
    public function init()
    {
        if ($version = $this->request->request('version')) {
            $lng = $this->request->request('lng');
            $lat = $this->request->request('lat');
            $content = [
                'citydata'    => Area::getCityFromLngLat($lng, $lat),
                'versiondata' => Version::check($version),
                'uploaddata'  => Config::get('upload'),
                'coverdata'   => Config::get("cover"),
            ];
            $this->success('', $content);
        } else {
            $this->error(__('Invalid parameters'));
        }
    }

    /**
     * oss上传凭证
     */
    public function ossToken(){
        $is_overseas = $this->request->param("is_overseas");
        if(empty($is_overseas)){
            $ip = $this->request->ip();
            $ip2 = new Ip2Region();
            $ip_address = $ip2->binarySearch($ip);
            $address = explode('|', $ip_address['region']);
            if ($address[0] == "中国" && !in_array($address[2], ["澳门", '香港', "台湾省","台湾"])) {
                $is_overseas = 10;
            } else {
                $is_overseas = 20;
            }
        }
        if($is_overseas==20){
            $oss_config = OssConfig::where("status",1)
                ->where("name","g_oss")
                ->find();
        }else{
            $oss_config = OssConfig::where("status",1) 
                ->where("name","oss")
                ->find();
        }
        $oss = new Oss($oss_config);
        $result = $oss->policy();
        if($is_overseas==20){
//            if($this->auth->id==6){
                $result["host"] = "https://upload.go0app.com/index/upload";
//            }else{
//                $result["host"] = "https://upload.qksign88.com:7326/";
//            }
//            $result["host"] = "https://upload.qksign88.com:7326/";
        }

        if($is_overseas==10){  //国内
            $result["host"] = "https://upload.go0app.com/index/upload";
        }
        $this->success('success',$result,200);
    }

    /**
     * ossipa解析
     */
    public function ipaParsing(){
        $osspath = $this->request->post('path');
        $is_overseas = $this->request->param("is_overseas");
        if(empty($osspath)) $this->error('解析错误');
        if($is_overseas==20){
            $host = "35.227.214.161";
        }else{
            $host = Config::where("name","ipa_parsing")
                ->value("value");
        }
        $url = "http://".$host."/index/ipaParsing";
        $post_data = [
            'oss_path'=>$osspath,
            'user_id'=>$this->auth->id
        ];
        $result = $this->http_request($url,$post_data);
        $data = json_decode($result,true);
        if(!$data["result"]){ 
            $this->error('解析失败',$result);
        }
        if(isset($data["result"]["is_error_dlib"])){
            $url = "https://api.telegram.org/bot1570861671:AAFoeYznUNYhNGgj5yKFby36SoNAITetkmc/sendMessage";
            $post_data = [
                "chat_id" => "-1001463689548",
                "text" => "APP: ".$result['display_name']." ，存在 < ZXRequestBlock.framework > 注入库，用户ID：".$this->auth->id." 及时查看",
            ];
            $tel_result = $this->http_request($url, $post_data);
        }
        $data['result']["url"]=$osspath;
        $this->success('success',$data['result'],200);
    }

    /**
     * 签名url
     */
    public function signUrl(){
        $obj =$this->request->param("obj");
        $ip = $this->request->ip();
        $ip2 = new Ip2Region();
        $ip_address = $ip2->binarySearch($ip);
        $address = explode('|',$ip_address['region']);
        if($address[0]=="中国" && !in_array($address[2],["澳门",'香港',"台湾省","台湾"]) ){
            $oss_config = OssConfig::where("status",1)
                ->where("name","oss")
                ->find();
        }else{
            $oss_config = OssConfig::where("status",1)
                ->where("name","g_oss")
                ->find();
        }
        $oss = new Oss($oss_config);
        $result=$oss->signUrl($obj);
        $this->success('success',$result,200);
    }



}
