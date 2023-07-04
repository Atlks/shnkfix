<?php


namespace app\api\controller;


use app\common\controller\Api;
use app\common\library\IosPackage;
use app\common\model\ProxyApp;
use app\common\model\ProxyUser;
use app\common\model\ProxyUserDomain;
use think\Db;

class Task extends Api
{

    protected $noNeedLogin="*";

    protected $noNeedRight="*";

    /***
     * mac 签名更新
     */
    protected function mac_sign_return(){
        $post = $this->request->post();
        if(empty($post["account_id"])||empty($post["sign"])||empty($post["app_id"])){
            $this->error("fail");
        }
        $sign = md5("macsignmdm".$post["account_id"]);
        if($sign!=$post["sign"]){
            $this->error("fail");
        }
        $update = [
            "id"=>$post["app_id"],
            "account_id" => $post["account_id"],
            "oss_path" => $post["oss_path"],
            "update_time" => date("Y-m-d H:i:s"),
            'package_name' => $post['package_name'],
        ];
        if(ProxyApp::update($update)){
            $this->success("success",null,200);
        }else{
            $this->error("fail");
        }
    }


}