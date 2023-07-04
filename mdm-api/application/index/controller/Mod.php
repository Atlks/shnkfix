<?php

namespace app\controller;

use app\common\controller\Frontend;
use app\common\library\Alipay;
use app\common\library\IosPackage;
use app\common\library\Ip2Region;
use app\common\library\Redis;
use app\common\library\WyDun;
use app\common\model\DownloadCode;
use app\common\model\ProxyApp;
use app\common\model\ProxyAppApkDownloadLog;
use app\common\model\ProxyUser;
use app\common\model\ProxyAppView;
use app\common\model\ProxyBaleRate;
use app\common\model\Config;
use app\common\model\ProxyDownload;
use app\common\model\ProxyUserDomain;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Exception;
use think\exception\DbException;
use think\Request;

class Mod extends Frontend
{

    public function upload()
    {
return 777;
    }


    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';


    protected $user;

    public function __construct(Request $request = null)
    {
        parent::__construct($request);
        $this->user = session('user');
        $this->assign('user', $this->user);
        $this->assign('versiontime',time());
    }

    public function index()
    {

    }


}
