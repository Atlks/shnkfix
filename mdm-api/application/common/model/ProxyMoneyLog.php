<?php

namespace app\common\model;

use think\Model;


class ProxyMoneyLog extends Model
{

    

    //数据库
    protected $connection = 'database';
    // 表名
    protected $table = 'proxy_user_money_log';
    
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = false;
    protected $deleteTime = false;

    // 追加属性
    protected $append = [

    ];
    

    







    public function proxyuser()
    {
        return $this->belongsTo('app\admin\model\ProxyUser', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}
