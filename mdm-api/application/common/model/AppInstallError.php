<?php


namespace app\common\model;


use think\Model;

class AppInstallError extends Model
{
    protected $table="app_install_error";


    /**
     * 错误信息记录
     * @param $error_info
     * @param $post_data
     * @param string $tag
     * @param string $ip
     * @param int $app_id
     */
    public static function addError($error_info,$post_data,$tag="",$ip='',$app_id=0){
        $data =[
            "app_id"=>$app_id,
            "error_info"=>$error_info,
            "tag"=>$tag,
            "post_data"=>json_encode($post_data),
            "create_time"=>date("Y-m-d H:i:s"),
            "ip"=>$ip
        ];
        self::create($data);
    }

}