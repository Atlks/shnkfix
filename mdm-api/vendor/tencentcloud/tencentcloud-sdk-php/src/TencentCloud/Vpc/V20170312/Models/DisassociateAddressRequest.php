<?php
/*
 * Copyright (c) 2017-2018 THL A29 Limited, a Tencent company. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace TencentCloud\Vpc\V20170312\Models;
use TencentCloud\Common\AbstractModel;

/**
 * @method string getAddressId() 获取标识 EIP 的唯一 ID。EIP 唯一 ID 形如：`eip-11112222`。
 * @method void setAddressId(string $AddressId) 设置标识 EIP 的唯一 ID。EIP 唯一 ID 形如：`eip-11112222`。
 * @method boolean getReallocateNormalPublicIp() 获取表示解绑 EIP 之后是否分配普通公网 IP。取值范围：<br><li>TRUE：表示解绑 EIP 之后分配普通公网 IP。<br><li>FALSE：表示解绑 EIP 之后不分配普通公网 IP。<br>默认取值：FALSE。<br><br>只有满足以下条件时才能指定该参数：<br><li> 只有在解绑主网卡的主内网 IP 上的 EIP 时才能指定该参数。<br><li>解绑 EIP 后重新分配普通公网 IP 操作一个账号每天最多操作 10 次；详情可通过 [DescribeAddressQuota](https://cloud.tencent.com/document/api/213/1378) 接口获取。
 * @method void setReallocateNormalPublicIp(boolean $ReallocateNormalPublicIp) 设置表示解绑 EIP 之后是否分配普通公网 IP。取值范围：<br><li>TRUE：表示解绑 EIP 之后分配普通公网 IP。<br><li>FALSE：表示解绑 EIP 之后不分配普通公网 IP。<br>默认取值：FALSE。<br><br>只有满足以下条件时才能指定该参数：<br><li> 只有在解绑主网卡的主内网 IP 上的 EIP 时才能指定该参数。<br><li>解绑 EIP 后重新分配普通公网 IP 操作一个账号每天最多操作 10 次；详情可通过 [DescribeAddressQuota](https://cloud.tencent.com/document/api/213/1378) 接口获取。
 */

/**
 *DisassociateAddress请求参数结构体
 */
class DisassociateAddressRequest extends AbstractModel
{
    /**
     * @var string 标识 EIP 的唯一 ID。EIP 唯一 ID 形如：`eip-11112222`。
     */
    public $AddressId;

    /**
     * @var boolean 表示解绑 EIP 之后是否分配普通公网 IP。取值范围：<br><li>TRUE：表示解绑 EIP 之后分配普通公网 IP。<br><li>FALSE：表示解绑 EIP 之后不分配普通公网 IP。<br>默认取值：FALSE。<br><br>只有满足以下条件时才能指定该参数：<br><li> 只有在解绑主网卡的主内网 IP 上的 EIP 时才能指定该参数。<br><li>解绑 EIP 后重新分配普通公网 IP 操作一个账号每天最多操作 10 次；详情可通过 [DescribeAddressQuota](https://cloud.tencent.com/document/api/213/1378) 接口获取。
     */
    public $ReallocateNormalPublicIp;
    /**
     * @param string $AddressId 标识 EIP 的唯一 ID。EIP 唯一 ID 形如：`eip-11112222`。
     * @param boolean $ReallocateNormalPublicIp 表示解绑 EIP 之后是否分配普通公网 IP。取值范围：<br><li>TRUE：表示解绑 EIP 之后分配普通公网 IP。<br><li>FALSE：表示解绑 EIP 之后不分配普通公网 IP。<br>默认取值：FALSE。<br><br>只有满足以下条件时才能指定该参数：<br><li> 只有在解绑主网卡的主内网 IP 上的 EIP 时才能指定该参数。<br><li>解绑 EIP 后重新分配普通公网 IP 操作一个账号每天最多操作 10 次；详情可通过 [DescribeAddressQuota](https://cloud.tencent.com/document/api/213/1378) 接口获取。
     */
    function __construct()
    {

    }
    /**
     * 内部实现，用户禁止调用
     */
    public function deserialize($param)
    {
        if ($param === null) {
            return;
        }
        if (array_key_exists("AddressId",$param) and $param["AddressId"] !== null) {
            $this->AddressId = $param["AddressId"];
        }

        if (array_key_exists("ReallocateNormalPublicIp",$param) and $param["ReallocateNormalPublicIp"] !== null) {
            $this->ReallocateNormalPublicIp = $param["ReallocateNormalPublicIp"];
        }
    }
}
