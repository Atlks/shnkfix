<?php

namespace app\common\model;

use think\Model;


class PushCert extends Model
{

    

    //数据库
    protected $connection = 'database';
    // 表名
    protected $table = 'proxy_push_cert';
    
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = false;

    // 定义时间戳字段名
    protected $createTime = false;
    protected $updateTime = false;
    protected $deleteTime = false;

    // 追加属性
    protected $append = [

    ];
    



    







    public function app()
    {
        return $this->belongsTo('App', 'app_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
