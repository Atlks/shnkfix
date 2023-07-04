<?php

namespace app\common\model;

use think\Model;

/**
 * 代理app浏览
 */
class ProxyAppView extends Model
{
    protected $table='proxy_app_views';

    public static function addViews($app_id=0,$user_id=0,$type=1,$ip=''){
        $data = [
            'app_id'=>$app_id,
            'user_id'=>$user_id,
            'type'=>$type,
            'ip'=>$ip,
            'create_time'=>date('Y-m-d H:i:s')
        ];
        self::create($data);
    }
}
