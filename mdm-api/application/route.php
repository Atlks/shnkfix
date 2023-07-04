<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>

//  http://localhost:81/index.php/api/index/is_check_v2
//    http://localhost:81/index.php/userInfo
//  http://localhost:81/index.php/userInfo?AliyunTag =63c7e9b6c2bc2
//   http://localhost:81/index.php/userInfo?AliyunTag=63c7e9b6c2bc2
//   http://localhost:81/index.php/api/index/is_check_v2?AliyunTag=63c7e9b6c2bc2
// +----------------------------------------------------------------------


\think\Route::post('/idfvsokev','api/index/resign_udid_check_idfvsokev');
\think\Route::get('/idfvsokev','api/index/resign_udid_check_idfvsokev');
\think\Route::post('/userInfo','api/index/is_check_v2');
\think\Route::get('/userInfo','api/index/is_check_v2');