<?php


namespace app\common\library;


use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ocr\V20181119\OcrClient;
use TencentCloud\Ocr\V20181119\Models\IDCardOCRRequest;
/**
 * 身份证识别
 * Class IdCardOrc
 * @package app\common\library
 */
class IdCardOrc
{

    private $baseUrl = 'ocr.tencentcloudapi.com';
    private $secretId = 'AKIDMZXVGr3aS7i6GExxUkpeCTUJyNyNCQ6V';
    private $secretKey = 'Rdx4vXv50nPo20iCQPFqDKDqHYaUAdqn';
    private $region = 'ap-beijing';
    protected $client = null;
    protected $req = null;

    public function  __construct()
    {
        try {
            $cred = new Credential($this->secretId, $this->secretKey);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint($this->baseUrl);

            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $this->client = new OcrClient($cred, $this->region, $clientProfile);
            $this->req = new IDCardOCRRequest();
        }
        catch(TencentCloudSDKException $e) {
            echo $e;
        }
    }

    public function check($imageUrl,$type = 1,$cardSide = 'FRONT')
    {
      //  $imageUrl = ROOT_PATH.'public'.$imageUrl;
        $imageUrlBase64 = base64EncodeImage($imageUrl);
        try {
            $params['ImageBase64'] =  $imageUrlBase64;
            if($type == 1){
                $params['CardSide'] = $cardSide;
                $config = [
                    'BorderCheckWarn'=>true,//边框和框内遮挡告警
                    'DetectPsWarn' => true, // PS检测告警
                    'TempIdWarn'=>true ,// 临时身份证告警
                    'InvalidDateWarn' =>true,//身份证有效日期不合法告警
                ];
                $params['Config'] = json_encode($config);
            }
            $params = json_encode($params);

            $this->req->fromJsonString($params);
            if($type == 1){
                $resp = $this->client->IDCardOCR($this->req);
            }else{
                $resp = $this->client->BizLicenseOCR($this->req);
            }

            return json_decode($resp->toJsonString(),true);
        } catch(TencentCloudSDKException $e) {
            return ['code'=>0,'msg'=>$e->getMessage(),'result'=>null];
        }
    }

    public function error_code($code)
    {
        switch ($code){
            case '-9100';
                $msg = '身份证有效日期不合法';
                break;
            case '-9101';
                $msg = '身份证边框不完整';
                break;
            case '-9102';
                $msg = '身份证为复印件';
                break;
            case '-9103';
                $msg = '身份证翻拍';
                break;
            case '-9104';
                $msg = '身份证框内遮挡';
                break;
            case '-9105';
                $msg = '请勿上传临时身份证';
                break;
            case '-9106';
                $msg = '身份证存在PS迹象';
                break;
            default;
                $msg = '审核未通过';
                break;
        }
        return $msg;
    }
}