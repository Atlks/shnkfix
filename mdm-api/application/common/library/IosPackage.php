<?php


namespace app\common\library;


use app\common\model\OssConfig;
use CFPropertyList\CFDictionary;
use CFPropertyList\CFPropertyList;
use CFPropertyList\IOException;
use CFPropertyList\PListException;
use Chumper\Zipper\Zipper;
use DOMException;
use fast\Random;
use ZIPARCHIVE;

class IosPackage
{

    public static function get_MDMConfig($app_name,$checkIn,$server,$tag,$extend=[]){
        $path = ROOT_PATH."/runtime/mdm/".Random::alnum()."/";
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        $out_name = "m_".$tag.".mobileconfig";
        exec("rm $path/*.mobileconfig");
        $str = file_get_contents(ROOT_PATH."/extend/MDM.mobileconfig");
        $new_str = str_replace(["App-Name","youCheckInURL","youServerURL"],[$app_name,$checkIn,$server],$str);
        file_put_contents($path."/mdm.mobileconfig",$new_str);
        $oss_config = OssConfig::where("status", 1)
            ->where("name", "g_oss")
            ->find();
        $oss = new Oss($oss_config);
        if ($oss->ossDownload($extend['pem_path'], $path . 'ios.pem') &&
            $oss->ossDownload($extend['cert_path'], $path . 'ios.crt') &&
            $oss->ossDownload($extend['key_path'], $path . 'ios.key')) {

        } else {
            return false;
        }
        $shell = "cd $path && openssl smime -sign -in mdm.mobileconfig -out $out_name -signer ios.crt -inkey ios.key -certfile ios.pem -outform der -nodetach";
        exec($shell, $log, $status);
        if ($status < 5) {
            $save_name = 'uploads/' . date('Ymd') . '/' . $out_name;
            if (is_file($path . $out_name)) {
                $oss->ossUpload($path . $out_name, $save_name);
            } else {
                return false;
            }
            $del = new self();
            $del->delDir($path);
            return $save_name;
        } else {
            $del = new self();
            $del->delDir($path);
            return false;
        }
    }
    /**
     * 获取加密描述文件
     * @param string $app_name
     * @param string $tag
     * @param string $callback
     * @param string $bundle
     * @param array $extend
     * @param string $token
     * @return bool|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function getMobileConfig($app_name = '', $tag = '', $callback = '', $bundle = 'dev.skyfox.profile-service', $extend = [], $token = "")
    {
        $name = $tag . '.mobileconfig';
        $path = ROOT_PATH . '/runtime/openssl/' . uniqid() . '/';
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        exec("rm $path/*.mobileconfig");
        $cache_path = $path . $name;
        $out_name = $tag . '_sign.mobileconfig';
        $str = <<<ETO
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
    <dict>
        <key>PayloadContent</key>
        <dict>
            <key>URL</key>
            <string>$callback</string>
            <key>DeviceAttributes</key>
            <array>
                <string>UDID</string>
                <string>IMEI</string>
                <string>ICCID</string>
                <string>VERSION</string>
                <string>PRODUCT</string>
            </array>
        </dict>
        <key>PayloadOrganization</key>
        <string>授权安装进入下一步</string>
        <key>PayloadDisplayName</key>
        <string>$app_name --【点击安装】</string>
        <key>PayloadVersion</key>
        <integer>1</integer>
        <key>PayloadUUID</key>
        <string>$token</string>
        <key>PayloadIdentifier</key>
        <string>$bundle</string>
        <key>PayloadDescription</key>
        <string>该配置文件帮助用户进行APP授权安装！This configuration file helps users with APP license installation!</string>
        <key>PayloadType</key>
        <string>Profile Service</string>
    </dict>
</plist>
ETO;
        file_put_contents($cache_path, $str);
        $oss_config = OssConfig::where("status", 1)
            ->where("name", "g_oss")
            ->find();
        $oss = new Oss($oss_config);
        if ($oss->ossDownload($extend['pem_path'], $path . 'ios.pem') &&
            $oss->ossDownload($extend['cert_path'], $path . 'ios.crt') &&
            $oss->ossDownload($extend['key_path'], $path . 'ios.key')) {

        } else {
            return false;
        }
        $shell = "cd $path && openssl smime -sign -in $name -out $out_name -signer ios.crt -inkey ios.key -certfile ios.pem -outform der -nodetach";
        exec($shell, $log, $status);
        if ($status == 0) {
            $save_name = 'uploads/' . date('Ymd') . '/' . $out_name;
            if (is_file($path . $out_name)) {
                $oss->ossUpload($path . $out_name, $save_name);
            } else {
                return false;
            }
            $del = new self();
            /***获取其他证书**/
            $del->getOtherMobileConfig($app_name, $path, $tag, $callback, $bundle, $token);
            $del->delDir($path);
            return $save_name;
        } else {
            $del = new self();
            $del->delDir($path);
            return false;
        }

    }

    /**
     * 获取其他语言描述文件
     * @param string $name
     * @param string $path
     * @param string $tag
     * @param string $callback
     * @param string $bundle
     * @param string $token
     * @return bool
     */
    public function getOtherMobileConfig($name = "", $path = "", $tag = "", $callback = '', $bundle = 'dev.skyfox.profile-service', $token = "")
    {
        $list = [
            "en" => [
                1 => "Authorize installation to the next step",
                2 => "Click install",
                3 => "This configuration file helps users to authorize the installation of APP！",
            ],
            /**越南***/
            "vi" => [
                1 => "Cho phép cài đặt sang bước tiếp theo",
                2 => "Bấm cài đặt",
                3 => "Tệp cấu hình này giúp người dùng cho phép cài đặt APP！",
            ],
            /**印尼**/
            "id" => [
                1 => "Otorisasi penginstalan ke langkah berikutnya",
                2 => "Klik install",
                3 => "File konfigurasi ini membantu pengguna untuk mengotorisasi penginstalan APP！",
            ],
            /***泰语**/
            "th" => [
                1 => "อนุญาตการติดตั้งในขั้นตอนถัดไป",
                2 => "คลิกติดตั้ง",
                3 => "ไฟล์กำหนดค่านี้ช่วยให้ผู้ใช้อนุญาตการติดตั้ง APP！",
            ],
            /**韩语**/
            "ko" => [
                1 => "다음 단계로 설치 권한 부여",
                2 => "클릭 설치",
                3 => "이 구성 파일은 사용자가 APP 설치를 승인하는 데 도움이됩니다.！",
            ],
            /**日语**/
            "ja" => [
                1 => "次のステップへのインストールを承認します",
                2 => "[インストール]をクリックします",
                3 => "この構成ファイルは、ユーザーがAPPのインストールを承認するのに役立ちます！",
            ],
            "hi" => [
                1 => "अगला कदम स्थापना के अधिकार देना है",
                2 => "इंस्टॉल पर क्लिक करें",
                3 => "यह कॉन्फ़िगरेशन फ़ाइल उपयोगकर्ताओं को एपीपी की स्थापना को अधिकृत करने में मदद करती है!",
            ]
        ];
        $oss_config = OssConfig::where("status", 1)
            ->where("name", "g_oss")
            ->find();
        $oss = new Oss($oss_config);
        foreach ($list as $k => $v) {
            $a = $v[1];
            $b = $v[2];
            $c = $v[3];
            $mobileName = $tag . '_' . $k . '.mobileconfig';
            $sign_name = $tag . '_' . $k . '_sign.mobileconfig';
            $cache_path = $path . $mobileName;
            $out_name = $path . $sign_name;
            $str = <<<ETO
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
    <dict>
        <key>PayloadContent</key>
        <dict>
            <key>URL</key>
            <string>$callback</string>
            <key>DeviceAttributes</key>
            <array>
                <string>DEVICE_NAME</string>
                <string>UDID</string>
                <string>IMEI</string>
                <string>ICCID</string>
                <string>VERSION</string>
                <string>PRODUCT</string>
                <string>SERIAL</string>
                <string>MAC_ADDRESS_EN0</string>
            </array>
            <key>Challenge</key>
            <string>kkipa</string>
            <key>ABC</key>
            <string>defrt</string>
        </dict>
        <key>PayloadOrganization</key>
        <string>$a</string>
        <key>PayloadDisplayName</key>
        <string>$name --【$b 】</string>
        <key>PayloadVersion</key>
        <integer>1</integer>
        <key>PayloadUUID</key>
        <string>$token</string>
        <key>PayloadIdentifier</key>
        <string>$bundle</string>
        <key>PayloadDescription</key>
        <string>$c This configuration file helps users with APP license installation!</string>
        <key>PayloadType</key>
        <string>Profile Service</string>
    </dict>
</plist>
ETO;
            file_put_contents($cache_path, $str);
            $shell = "cd $path && openssl smime -sign -in $mobileName -out $sign_name -signer ios.crt -inkey ios.key -certfile ios.pem -outform der -nodetach";
            exec($shell);
            if (is_file($out_name)) {
                $oss->ossUpload($out_name, 'uploads/' . date('Ymd') . '/' . $sign_name);
            }
        }
        return true;
    }

    /***
     * 防闪退
     * @param $name
     * @param $tag
     * @param $url
     * @param $extend
     * @return bool|string
     */
    public static function st($name, $tag, $url, $extend)
    {
        $path = ROOT_PATH . '/runtime/openssl/' . uniqid() . '/';
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        exec("rm $path/*.mobileconfig");
        $cache_path = $path . "st.mobileconfig";
        $out_name = $tag . '_st.mobileconfig';
        $str = <<<ETO
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>ConsentText</key>
	<dict/>
	<key>PayloadContent</key>
	<array>
		<dict>
			<key>FullScreen</key>
			<true/>
			<key>Icon</key>
			<data>
			iVBORw0KGgoAAAANSUhEUgAABAAAAAQACAIAAADwf7zUAAAAAXNS
			R0IArs4c6QAAAERlWElmTU0AKgAAAAgAAYdpAAQAAAABAAAAGgAA
			AAAAA6ABAAMAAAABAAEAAKACAAQAAAABAAAEAKADAAQAAAABAAAE
			AAAAAADT3eodAABAAElEQVR4AeydfayV1ZW4aYdGTOuANVPFxFoy
			tpZOxxZtNIMZJqXRpjg6BvxoYLSmIE4C2DaAsUESG7iJCiQq199U
			BVKVy1QppJVAMzZiBjMYaZRiWyl1Jkhsi3Q6FZo61UTj79FT8XK5
			H+855/3YH8/543ruOe+791rPeq+stffaa73vrbfeGuVLAhKQgAQk
			IAEJSEACEsiDwPvzUFMtJSABCUhAAhKQgAQkIIG3CRgA+BxIQAIS
			kIAEJCABCUggIwIGABkZW1UlIAEJSEACEpCABCRgAOAzIAEJSEAC
			EpCABCQggYwIGABkZGxVlYAEJCABCUhAAhKQgAGAz4AEJCABCUhA
			AhKQgAQyImAAkJGxVVUCEpCABCQgAQlIQAIGAD4DEpCABCQgAQlI
			QAISyIiAAUBGxlZVCUhAAhKQgAQkIAEJGAD4DEhAAhKQgAQkIAEJ
			SCAjAgYAGRlbVSUgAQlIQAISkIAEJGAA4DMgAQlIQAISkIAEJCCB
			jAgYAGRkbFWVgAQkIAEJSEACEpCAAYDPgAQkIAEJSEACEpCABDIi
			YACQkbFVVQISkIAEJCABCUhAAgYAPgMSkIAEJCABCUhAAhLIiIAB
			QEbGVlUJSEACEpCABCQgAQkYAPgMSEACEpCABCQgAQlIICMCBgAZ
			GVtVJSABCUhAAhKQgAQkYADgMyABCUhAAhKQgAQkIIGMCBgAZGRs
			VZWABCQgAQlIQAISkIABgM+ABCQgAQlIQAISkIAEMiJgAJCRsVVV
			AhKQgAQkIAEJSEACBgA+AxKQgAQkIAEJSEACEsiIgAFARsZWVQlI
			QAISkIAEJCABCRgA+AxIQAISkIAEJCABCUggIwIGABkZW1UlIAEJ
			SEACEpCABCRgAOAzIAEJSEACEpCABCQggYwIGABkZGxVlYAEJCAB
			CUhAAhKQgAGAz4AEJCABCUhAAhKQgAQyImAAkJGxVVUCEpCABCQg
			AQlIQAIGAD4DEpCABCQgAQlIQAISyIiAAUBGxlZVCUhAAhKQgAQk
			IAEJGAD4DEhAAhKQgAQkIAEJSCAjAgYAGRlbVSUgAQlIQAISkIAE
			JGAA4DMgAQlIQAISkIAEJCCBjAgYAGRkbFWVgAQkIAEJSEACEpCA
			AYDPgAQkIAEJSEACEpCABDIiYACQkbFVVQISkIAEJCABCUhAAgYA
			PgMSkIAEJCABCUhAAhLIiIABQEbGVlUJSEACEpCABCQgAQkYAPgM
			SEACEpCABCQgAQlIICMCBgAZGVtVJSABCUhAAhKQgAQkYADgMyAB
			CUhAAhKQgAQkIIGMCBgAZGRsVZWABCQgAQlIQAISkIABgM+ABCQg
			AQlIQAISkIAEMiJgAJCRsVVVAhKQgAQkIAEJSEACBgA+AxKQgAQk
			IAEJSEACEsiIgAFARsZWVQlIQAISkIAEJCABCRgA+AxIQAISkIAE
			JCABCUggIwIGABkZW1UlIAEJSEACEpCABCRgAOAzIAEJSEACEpCA
			BCQggYwIGABkZGxVlYAEJCABCUhAAhKQgAGAz4AEJCABCUhAAhKQ
			gAQyImAAkJGxVVUCEpCABCQgAQlIQAIGAD4DEpCABCQgAQlIQAIS
			yIiAAUBGxlZVCUhAAhKQgAQkIAEJGAD4DEhAAhKQgAQkIAEJSCAj
			AgYAGRlbVSUgAQlIQAISkIAEJGAA4DMgAQlIQAISkIAEJCCBjAgY
			AGRkbFWVgAQkIAEJSEACEpCAAYDPgAQkIAEJSEACEpCABDIiYACQ
			kbFVVQISkIAEJCABCUhAAgYAPgMSkIAEJCABCUhAAhLIiIABQEbG
			VlUJSEACEpCABCQgAQkYAPgMSEACEpCABCQgAQlIICMCBgAZGVtV
			JSABCUhAAhKQgAQkYADgMyABCUhAAhKQgAQkIIGMCBgAZGRsVZWA
			BCQgAQlIQAISkIABgM+ABCQgAQlIQAISkIAEMiJgAJCRsVVVAhKQ
			gAQkIAEJSEACBgA+AxKQgAQkIAEJSEACEsiIgAFARsZWVQlIQAIS
			kIAEJCABCRgA+AxIQAISkIAEJCABCUggIwIGABkZW1UlIAEJSEAC
			EpCABCRgAOAzIAEJSEACEpCABCQggYwIGABkZGxVlYAEJCABCUhA
			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
			AAEIQCAlAhgAKYGkGghAAAIQgAAEIAABCLhAAAPAhVGijxCAAAQg
			AAEIQAACEEiJAAZASiCpBgIQgAAEIAABCEAAAi4QwABwYZToIwQg
			AAEIQAACEIAABFIigAGQEkiqgQAEIAABCEAAAhCAgAsEMABcGCX6
			CAEIQAACEIAABCAAgZQIYACkBJJqIAABCEAAAhCAAAQg4AIBDAAX
			Rok+QgACEIAABCAAAQhAICUCGAApgaQaCEAAAhCAAAQgAAEIuEAA
			A8CFUaKPEIAABCAAAQhAAAIQSIkABkBKIKkGAhCAAAQgAAEIQAAC
			LhDAAHBhlOgjBCAAAQhAAAIQgAAEUiKAAZASSKqBAAQgAAEIQAAC
			EICACwQwAFwYJfoIAQhAAAIQgAAEIACBlAhgAKQEkmogAAEIQAAC
			EIAABCDgAgEMABdGiT5CAAIQgAAEIAABCEAgJQIYACmBpBoIQAAC
			EIAABCAAAQi4QAADwIVRoo8QgAAEIAABCEAAAhBIiQAGQEogqQYC
			EIAABCAAAQhAAAIuEMAAcGGU6CMEIAABCEAAAhCAAARSIoABkBJI
			qoEABCAAAQhAAAIQgIALBDAAXBgl+ggBCEAAAhCAAAQgAIGUCGAA
			pASSaiAAAQhAAAIQgAAEIOACAQwAF0aJPkIAAhCAAAQgAAEIQCAl
			AhgAKYGkGghAAAIQgAAEIAABCLhAAAPAhVGijxCAAAQgAAEIQAAC
			EEiJAAZASiCpBgIQgAAEIAABCEAAAi4QwABwYZToIwQgAAEIQAAC
			EIAABFIigAGQEkiqgQAEIAABCEAAAhCAgAsEMABcGCX6CAEIQAAC
			EIAABCAAgZQIYACkBJJqIAABCEAAAhCAAAQg4AIBDAAXRok+QgAC
			EIAABCAAAQhAICUCGAApgaQaCEAAAhCAAAQgAAEIuEAAA8CFUaKP
			EIAABCAAAQhAAAIQSIkABkBKIKkGAhCAAAQgAAEIQAACLhDAAHBh
			lOgjBCAAAQhAAAIQgAAEUiKAAZASSKqBAAQgAAEIQAACEICACwQw
			AFwYJfoIAQhAAAIQgAAEIACBlAhgAKQEkmogAAEIQAACEIAABCDg
			AgEMABdGiT5CAAIQgAAEIAABCEAgJQIYACmBpBoIQAACEIAABCAA
			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
			Hui0HtQCv/xRNRAKTtPL0KNHD9lyld/ftK4VJ5BWn6kHAmEEcAFK
			6wtb0HqsXIDSYqRF67POOkuZ9eqpUKuw8hh5+umnbSvRwu3zzz+/
			5ZZb2j6YW3lbR6CyIt/85jfvuuuu8r6NFrB1CFT5bsMu5Mghd5rY
			5rQie/XVV8t/KaP0srEdKAkos+qtt94aLazVxIEDB6611lrRYoF3
			nXABquq5dv8qk4dW3c3uoyIyu3fvnl39sTV36NBBrl+77rprrKQE
			cAEyoZSijFb39cMilx5Zd1o+ULjO/PnztTafYhMRVf3+97/fa6+9
			IgS4BYF6CYRZBpRDwIRAg3cAyq+7/nBqk92khxEyDzzwQLlCqwv9
			zY6o1sVbZ5xxhhWBsnDlcnXiSsq1NeCi6VsBDz30kImasqaSJY50
			bgfA3K8m9W+W7Q6AycDZyqy55prXXnutSVQ0OwCpvwDlCuWp/9pr
			ryn5m7Iey9VTC1vK2mQ7lOnKqwM6+7ncQy4gkDqBJCtM6b7l1AaB
			BASeeOKJPffcU248Wigtr0Db1qMYRGW2sUoKVGriX//6l21bOZcf
			NmzYzTffvGzZMtt+/u1vfys/sttuu5Wvc3uhALt27do1cStAS90K
			otCuSzSi6dOnK1JF05Htt98+WtLpu3KbbtYiQk64ySvslFNO0dLy
			ZZddttlmm+WkV752Qxu/CiTTD93bb7+t1X2dmvf666/ri6Y/KHlT
			WWfqXXfddUoflLeO0R9/CKRuUlBhoQg0/Y+3JvFyyEnMXDGXCZw4
			lW4ycYu5ffCWW25J8LtWeVib/mIlqKFZjzRxK0BnXBhq3apVK+0Y
			WL0zDu0AKBt6sl0OKyARwnnYASi/CQob0J5kRG/ZAYiAU74lg2r5
			8uVazn/yySf1Q611DSVjPfPMM7/zne+YBGaUhyMPF9odeumll8qq
			cQGBdAmwA5CHrzl9SE5AMcEKqZQvb2D+0Nh6W7durT8PSr0cK1kW
			UAyAsoKWP3pzoYA2nZ9lPjeV4gqJrsxmqGODHaLRxK2AQw89VCcw
			vPjii7G45HCsPK06MVQeQankwI1tsWECmtzceOONX/7ylxvWYs4b
			UvxM586dle9YYSo572pzu6ddxw8++EDZCzTR13K+bEidQaGlHIXn
			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
			W1Jk9erVCTTabbfdcmgAlBQpuQPdeuutG2+8cQLVeKSKgHKtnnLK
			KZr9q3zEiBFz5szRieCe7QxXqczHvBHAAMjbiBSoPzqFNFbbgw46
			SAuisWIINJeAjuS07QDTiFpi8gd4+eWX5fCG638tnDyUKCpD/7Lr
			yfbbb59d5fXXrETMJC6rH2Ophp/85CdyryrXpmA2hftfddVV8v1L
			8HNarocLCJgT4BwAc1ZIpkzgN7/5TWyN2hjFEzqWUtMFFM5h24eW
			LVvaPuK3vNzhzjrrrNNOO02rgDqQ1cQ7zm8gBdSuYa5fCdheeuml
			P/zhDzkLPAG62keU21c7KrXlp59+uhKCJc7dVFshJRCIIMAOQAQc
			bmVIQFufsQbAtttuK6/iDDtB1c0jgAFQxV6+H6U9sddff12bAJdc
			cok8g7t06ZIgvqKqZj66QiCHiYBK6Jo1+9efiWTOVAlG/MMPP7R9
			avny5QrasX3qrbfe6t27d9hT8+bNa9eu3fXXX3/88cf7lCkuTF/K
			m0gAA6CJ8AvdtPLGxOqvw7/WWWedWDEEmk7gX//6l20fiAGoJKaZ
			xDnnnFNZ8vTTTytLko4Ak8+JHOFYea2E4+u1HGyUDypvC8DNmv1r
			lKdMmaJN4NwOt5JMZNE3/ZyecMIJigoYN24cyRKyIEydJQIYALwJ
			zSGwaNGi6IZ1BurRRx8dLcPdnBBIsErXunXrnHQ+D93QRCcwf45O
			B9M/fRHOO++8Dh065KGr9CFTAvKzl39IYBM6CViLx4G3Agu1erLF
			FlvU3lL6tf/85z+15WEl2o9qlv0pcyisV96X33TTTffee6+2AnRI
			SLP4ew+54AoSA1DwF6Bp6j/xxBPRbWtBNM8BZ/LTkL/m0qVLo7Uo
			yF1ltLDVdLPNNrN9xFf5N954Q0fdRWg3c+bMPffcs3///i+99FKE
			GLc8IHD55ZcvDvlneGpKGcKZZ54ZWFOnTp3KMiYXTUzu1LZtW5Me
			+irz17/+VSEBAwcO1IWvOqJXEwmwA9BE+MVt+uOPP547d260/mGH
			f0U/1Zi76v8FF1zw85//XMGaEyZMOPbYYwvuqL1ixQpb8l/+8pdt
			H3FIXr4BTz31lGGH5f9j4kOlXQL9Gzp06BlnnKET6wwrjxbTwRcs
			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
			6d69e9it2nLbP5+1NThXovVC+RUYdnvAgAGnn366oXClmHLDjx8/
			vrLE8Hrbbbc1lCyC2HvvvScDLDtNFa37t7/9LbD+9u3b6+i9+odD
			aWEC6y9OoX6+tDta0lf7M/K+M9T9+9//vubr0cK2iYx18kN0hdyF
			AATcJYAB4O7YFbrnWrkPXJrScrU5F4Wfdu7c2Vy+aDEA2vFQ5h9D
			1w6FUmgSb+j6X8tc05foA4xqH1GJ1fAF1uBToQ5p/utf/5qdRppf
			luemta3ofF/5q+y00061t8xLCpvSRK75v/rVr3RAnowoxdCXiClZ
			qjm6Y445JlY44vT0wGcxAAKxUAgBPwhgAPgxjoXT4tvf/natzpqq
			KqK0tjysRMvV5v4/qsTW4cSq8rBONrH8F7/4haE/lU5SU1LIemIe
			dGCw5q9Wyip8MHAXyKoSb4Tvu+++kSNHZqfO0KFDn3vuuWiPZ6XT
			lQ/SPvvsk6wbOsQqNnVssppz/tQFF1ygrQ/tRip6Xm5UCm3XT43M
			b0VFG/Zc/pAdO3aMFbbdAciJd36sXghAAAIJCGAAJIDGI00moOmm
			joKq7YQmH1b+P+WVttqqAktsDYDAPYrAmnNY+PTTTyvPpknHNGmb
			MWNG/c4byqehFVCTFksyJ510krmw35Lyzg80iVPRWk75Cv8dO3as
			SZSkTLJZs2bFOqMHdkxRBE5/ZTRl1xqEciXJUrJyF6zdZHvzzTeV
			F9X8sGQdrmySrsfWANDBz4EjRSEEIOABAYKAPRjEwqkwbNiwWlcT
			Jau55pprzFnIhFDworm8JKvyoMc+myzDfWy1DRCQp8cJJ5xgYk0p
			bcvcuXPTOknt7LPP1vHAJo4KSlevxJENQJH/JpT43zyVrZU62sK6
			8sorjz/+eJPJZblmpUaRN4uceawy2Ohx2/DxcouNv9CvjWbtr732
			2htvvPHnP//5T3/6k+b9yktru0YQ1nPVrPyqYXdryw0P1LM1AOrZ
			06vtJCUQgECuCGAA5Go46Ew8AW2Ua6WwVk4ZhpRUtLY8rERHmerc
			nLC7geUffvhhYHlYYaaHaIY1Wn/56tWrf/CDH5isPmrtX7N/OX/X
			32ipBs0dFcZ66qmnxlZ43nnnOb1aHKuguYBGQYfZ3X777eaPmEjK
			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
			Cfw/pIBwmOyNBOYAAAAASUVORK5CYII=
			</data>
			<key>IsRemovable</key>
			<true/>
			<key>Label</key>
			<string>$name</string>
			<key>PayloadDescription</key>
			<string>APP闪退修复助手</string>
			<key>PayloadDisplayName</key>
			<string>$name</string>
			<key>PayloadIdentifier</key>
			<string>com.apple.webClip.managed.$tag</string>
			<key>PayloadType</key>
			<string>com.apple.webClip.managed</string>
			<key>PayloadUUID</key>
			<string>5C354E3E-20A4-4331-9190-635C80096660</string>
			<key>PayloadVersion</key>
			<integer>1</integer>
			<key>Precomposed</key>
			<false/>
			<key>URL</key>
			<string>$url</string>
		</dict>
	</array>
	<key>PayloadDescription</key>
	<string>$name 闪退助手，当应用闪退后，可通过本应用重新下载安装</string>
	<key>PayloadDisplayName</key>
	<string>$name</string>
	<key>PayloadIdentifier</key>
	<string>AppledeMacBook-Air-3.$tag</string>
	<key>PayloadOrganization</key>
	<string>apple.com</string>
	<key>PayloadRemovalDisallowed</key>
	<false/>
	<key>PayloadType</key>
	<string>Configuration</string>
	<key>PayloadUUID</key>
	<string>2BB9247A-572A-46BB-9562-B73FEA80736B</string>
	<key>PayloadVersion</key>
	<integer>1</integer>
</dict>
</plist>
ETO;
        file_put_contents($cache_path, $str);

        $oss_config = OssConfig::where("status", 1)
            ->where("name", "g_oss")
            ->find();
        $oss = new Oss($oss_config);
        if ($oss->ossDownload($extend['pem_path'], $path . 'ios.pem') &&
            $oss->ossDownload($extend['cert_path'], $path . 'ios.crt') &&
            $oss->ossDownload($extend['key_path'], $path . 'ios.key')) {

        } else {
            return false;
        }
        $shell = "cd $path && openssl smime -sign -in st.mobileconfig -out $out_name -signer ios.crt -inkey ios.key -certfile ios.pem -outform der -nodetach";
        exec($shell, $log, $status);
//        $oss = new Oss(config("g_oss"));
        if (is_file($path . $out_name)) {
            $oss->ossUpload($path . $out_name, 'uploads/' . date('Ymd') . '/' . $out_name);
            $self = new self();
            $self->delDir($path);
            return 'uploads/' . date('Ymd') . '/' . $out_name;
        } else {
            $self = new self();
            $self->delDir($path);
            return false;
        }
    }

    /***
     * 获取UDID
     * @param null $data
     * @return |null
     */
    public static function getUdid($data = null)
    {
        $plistBegin = '<?xml version="1.0"';
        $plistEnd = '</plist>';
        $pos1 = strpos($data, $plistBegin);
        $pos2 = strpos($data, $plistEnd);
        $data2 = substr($data, $pos1, $pos2 - $pos1);
        $xml = xml_parser_create();
        xml_parse_into_struct($xml, $data2, $vs);
        xml_parser_free($xml);
        $arrayCleaned = [];
        foreach ($vs as $v) {
            if ($v['level'] == 3 && $v['type'] == 'complete') {
                $arrayCleaned[] = $v;
            }
        }
        $result = null;
        foreach ($arrayCleaned as $k => $v) {
            if (isset($v['value']) && ($v['value'] == 'UDID' || $v['value'] == 'PRODUCT')) {
                $result[strtolower($v['value'])] = $arrayCleaned[$k + 1]['value'] ?? '';
            }
        }
        return $result;
    }

    /**
     * 获取描述文件之后信息
     * @param null $data
     * @return mixed|null
     */
    public static function getIosInfo($data = null)
    {
        $plistBegin = '<?xml version="1.0"';
        $plistEnd = '</plist>';
        $pos1 = strpos($data, $plistBegin);
        $pos2 = strpos($data, $plistEnd);
        $data2 = substr($data, $pos1, $pos2 - $pos1);
        $xml = xml_parser_create();
        xml_parse_into_struct($xml, $data2, $vs);
        xml_parser_free($xml);
        $arrayCleaned = [];
        foreach ($vs as $v) {
            if ($v['level'] == 3 && $v['type'] == 'complete') {
                $arrayCleaned[] = $v;
            }
        }
        $result = null;

        foreach ($arrayCleaned as $k => $v) {
            if (isset($v['value']) && ($v['value'] == 'UDID' || $v['value'] == 'PRODUCT')) {
                $result = $arrayCleaned[$k + 1]['value'];
            }
        }
        return $result;
    }

    /**
     * 获取ipa 包信息
     * @param string $app_path
     * @return array|bool
     * @throws IOException
     * @throws PListException
     * @throws DOMException
     */
    public function getIosPackage($app_path = '')
    {
        // 遍历zip包中的Info.plist文件
        $cacheZipPath = ROOT_PATH . '/runtime/app/plist/' . uniqid();
        $zipper = new Zipper();
        $zipFiles = $zipper->make($app_path)->listFiles('/Info\.plist$/i');
        $iconFiles = $zipper->make($app_path)->listFiles('/AppIcon[\s\S]*.png/i');
        if (empty($iconFiles)) {
            $iconFiles = $zipper->make($app_path)->listFiles('/[\s\S]*.png/i');
        }
        $iconFilesList = array_merge($zipFiles, $iconFiles);
        $result = [];
        if ($zipFiles) {
            // 将plist文件解压到ipa目录中的对应包名目录中
            $zip = new ZipArchive();
            $zip->open($app_path);
            $zip->extractTo($cacheZipPath, $iconFilesList);
            $zip->close();
            foreach ($zipFiles as $k => $filePath) {
                // 正则匹配包根目录中的Info.plist文件
                if (preg_match("/Payload\/([^\/]*)\/Info\.plist$/i", $filePath, $matches)) {
                    $app_folder = $matches[1];

                    // 拼接plist文件完整路径
                    $fp = $cacheZipPath . '/Payload/' . $app_folder . '/Info.plist';

                    // 获取plist文件内容
                    $content = file_get_contents($fp);

                    // 解析plist成数组
                    $ipa = new CFPropertyList();
                    $ipa->parse($content);
                    $ipaInfo = $ipa->toArray();
                    //ipa icon
                    if (isset($ipaInfo['CFBundleIcons']['CFBundlePrimaryIcon']['CFBundleIconFiles'])) {
                        $icon = end($ipaInfo['CFBundleIcons']['CFBundlePrimaryIcon']['CFBundleIconFiles']);
                    } elseif (isset($ipaInfo['CFBundleIcons']['CFBundlePrimaryIcon'])) {
                        $icon = end($ipaInfo['CFBundleIcons']['CFBundlePrimaryIcon']);
                    } elseif (!empty($ipaInfo['CFBundleIcons'])) {
                        $icon = end($ipaInfo['CFBundleIcons']);
                    } elseif (!empty($ipaInfo['CFBundleIconFiles'])) {
                        $icon = end($ipaInfo['CFBundleIconFiles']);
                    } else {
                        foreach ($iconFiles as $key => $value) {
                            if (strstr($value, 'Icon')) {
                                $icon = strstr($value, 'Icon');
                            }
                        }
                    }
                    $icon_path = $this->getIcon($cacheZipPath . '/Payload/' . $app_folder, $icon);
                    if ($icon_path) {
                        $icon_path = $this->getIconConversion($icon_path);
                    } else {
                        $icon_path = '';
                    }
                    // ipa 解包信息
                    $ipa_data_bak = $ipaInfo;
                    // 包名
                    $result['package_name'][] = $ipaInfo['CFBundleIdentifier'];

                    // 版本名
                    $version_code = $ipaInfo['CFBundleShortVersionString'];

                    // 版本号
                    $version_name = str_replace('.', '', $ipaInfo['CFBundleShortVersionString']);

                    // 别名
                    $bundle_name = isset($ipaInfo['CFBundleName']) ? $ipaInfo['CFBundleName'] : $ipaInfo['CFBundleDisplayName'];

                    // 显示名称
                    $display_name = isset($ipaInfo['CFBundleDisplayName']) ? $ipaInfo['CFBundleDisplayName'] : $ipaInfo['CFBundleName'];
                    $data = [
                        'icon' => $icon_path,
                        'ipa_data_bak' => $ipa_data_bak,
                        'version_name' => $version_name,
                        'version_code' => $version_code,
                        'bundle_name' => $bundle_name,
                        'display_name' => $display_name,
                    ];
                    $result = array_merge($data, $result);
                    continue;
                } /* elseif (preg_match("/Payload\/([\s\S]*)\/Info\.plist$/i", $filePath, $matches)) {
                    $app_folder = $matches[1];
                    // 拼接plist文件完整路径
                    $fp = $cacheZipPath.'/Payload/'. $app_folder . '/Info.plist';
                    // 获取plist文件内容
                    $content = file_get_contents($fp);

                    // 解析plist成数组
                    $ipa = new CFPropertyList();
                    $ipa->parse($content);
                    $ipaInfo = $ipa->toArray();
                    if (isset($ipaInfo['CFBundleIdentifier'])) {
                        // 包名
                        $result['package_name'][] = $ipaInfo['CFBundleIdentifier'];
                    }
                    continue;
                } */
                else {
                    continue;
                }
            }
            $this->delDir($cacheZipPath);
            $result['package_name'] = implode(',', $result['package_name']);
            return $result;
        }
        return false;
    }

    /**
     * 查找ICON
     * @param string $path
     * @param string $icon_name
     * @return bool|string
     */
    public function getIcon($path = '', $icon_name = '')
    {
        $filesnames = scandir($path);
        foreach ($filesnames as $k => $v) {
            if (strpos($v, $icon_name) === 0) {
                return $path . '/' . $v;
            } else {
                if ($v == '.' || $v == '..') {
                    continue;
                }
                $dir = $path . '/' . $v;
                if (is_dir($dir)) {
                    $this->getIcon($dir, $icon_name);
                } else {
                    continue;
                }
            }
        }
        return false;
    }

    /**
     * 图片解码
     * @param string $icon_path
     * @return string
     */
    public function getIconConversion($icon_path = '')
    {
        $file_name = trim(strrchr($icon_path, '/'), '/');
        $save_name = md5(time() . $file_name) . '.png';
        $save_path = ROOT_PATH . '/public/uploads/' . date('Ymd') . '/' . $save_name;
        $path = ROOT_PATH . '/task/' . uniqid() . '/';
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        copy(ROOT_PATH . '/task/ipin.py', $path . 'ipin.py');
        copy($icon_path, $path . $file_name);
        $exec = "cd $path && python ipin.py";
        exec($exec, $log, $status);
        copy($path . $file_name, $save_path);
        exec("rm -rf $path");
//        unlink($path . $file_name);
        return '/uploads/' . date('Ymd') . '/' . $save_name;
    }

    /**
     * 删除文件
     * @param $path
     * @return int
     */
    public function delDir($path)
    {
        if (!is_dir($path)) {
            return 0;
        }
        $handle = dir($path);
        while (false !== ($entry = $handle->read())) {
            if (($entry != ".") && ($entry != "..")) {
                if (is_file($path . "/" . $entry)) {
                    unlink($path . "/" . $entry);
                } else {
                    $this->delDir($path . "/" . $entry);
                }
            }
        }
        $handle->close();
        rmdir($path);
    }

    public function addTemporaryPList($appName = '', $udid = '', $bundleID = '', $logo = '', $callback = '')
    {
        $app_plist = <<<ETO
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>items</key>
	<array>
		<dict>
			<key>assets</key>
			<array>
				<dict>
					<key>kind</key>
					<string>software-package</string>
					<key>url</key>
					<string>$callback</string>
				</dict>
				<dict>
					<key>kind</key>
					<string>full-size-image</string>
					<key>needs-shine</key>
					<true/>
					<key>url</key>
					<string>$logo</string>
				</dict>
				<dict>
					<key>kind</key>
					<string>display-image</string>
					<key>needs-shine</key>
					<true/>
					<key>url</key>
					<string>$logo</string>
				</dict>
			</array>
			<key>metadata</key>
			<dict>
				<key>bundle-identifier</key>
				<string>$bundleID</string>
				<key>bundle-version</key>
				<string>1.0.0</string>
				<key>kind</key>
				<string>software</string>
				<key>title</key>
				<string>$appName</string>
			</dict>
		</dict>
	</array>
</dict>
</plist>
ETO;
        $date = date('Ymd');
        $save_path = ROOT_PATH . '/runtime/download/' . $date;
        if (!is_dir($save_path)) {
            mkdir($save_path, 0777, true);
        }
        $path_name = $save_path . '/' . $udid . '.plist';
        file_put_contents($path_name, $app_plist);
        return $path_name;
    }

    public function folderToZip($folder, &$zipFile, $exclusiveLength)
    {
        $handle = opendir($folder);
        while (false !== $f = readdir($handle)) {
            if ($f != '.' && $f != '..') {
                $filePath = "$folder/$f";
                // Remove prefix from file path before add to zip.
                $localPath = substr($filePath, $exclusiveLength);
                if (is_file($filePath)) {
                    $zipFile->addFile($filePath, $localPath);
                } elseif (is_dir($filePath)) {
                    // Add sub-directory.
                    $zipFile->addEmptyDir($localPath);
                    self::folderToZip($filePath, $zipFile, $exclusiveLength);
                }
            }
        }
        closedir($handle);
    }

    public function zipDir($sourcePath, $outZipPath)
    {
        $pathInfo = pathInfo($sourcePath);
        $parentPath = $pathInfo['dirname'];
        $dirName = $pathInfo['basename'];

        $z = new ZipArchive();
        $z->open($outZipPath, ZIPARCHIVE::CREATE);
        $z->addEmptyDir($dirName);
        $this->folderToZip($sourcePath, $z, strlen("$parentPath/"));
        $z->close();
    }

    public function createPlist($data = [], $savePath = '')
    {
        $plist = new CFPropertyList();
        $dict = new CFDictionary();

        $plist->add($dict);
        $plist->saveXML($savePath);
    }

    /**
     * 获取设备型号
     * @param string $product
     * @return string
     */
    public static function getDevices($product = '')
    {
        switch ($product) {
            case "iPod5,1":
                return "iPod Touch 5";
            case "iPod7,1":
                return "iPod Touch 6";
            case "iPhone3,1":
            case "iPhone3,2":
            case "iPhone3,3" :
                return "iPhone4";
            case"iPhone4,1":
                return "iPhone4s";
            case"iPhone5,1" :
            case "iPhone5,2":
                return "iPhone5";
            case "iPhone5,3" :
            case "iPhone5,4":
                return "iPhone5c";
            case "iPhone6,1":
            case "iPhone6,2":
                return "iPhone5s";
            case"iPhone7,2":
                return "iPhone6";
            case"iPhone7,1":
                return "iPhone6 Plus";
            case"iPhone8,1":
                return "iPhone6s";
            case"iPhone8,2":
                return "iPhone6s Plus";
            case"iPhone8,4":
                return "iPhoneSE";
            case"iPhone9,1" :
            case "iPhone9,3":
                return "iPhone7";
            case "iPhone9,2" :
            case "iPhone9,4":
                return "iPhone7 Plus";
            case "iPhone10,1" :
            case "iPhone10,4":
                return "iPhone8";
            case "iPhone10,5" :
            case "iPhone10,2":
                return "iPhone8 Plus";
            case "iPhone10,3" :
            case "iPhone10,6":
                return "iPhoneX";
            case"iPhone11,2":
                return "iPhoneXS";
            case"iPhone11,6":
                return "iPhoneXS_MAX";
            case"iPhone11,8":
                return "iPhoneXR";
            case "iPhone12,1":
                return "iPhone11";
            case "iPhone12,3":
                return "iPhone11 Pro";
            case "iPhone12,5":
                return "iPhone11 Pro Max";
            case "iPad2,1" :
            case"iPad2,2":
            case "iPad2,3":
            case"iPad2,4":
                return "iPad 2";
            case "iPad3,1":
            case"iPad3,2" :
            case"iPad3,3":
                return "iPad 3";
            case "iPad3,4":
            case"iPad3,5" :
            case "iPad3,6":
                return "iPad 4";
            case "iPad4,1" :
            case "iPad4,2" :
            case "iPad4,3":
                return "iPad Air";
            case"iPad5,3" :
            case "iPad5,4":
                return "iPad Air 2";
            case "iPad2,5":
            case "iPad2,6" :
            case"iPad2,7":
                return "iPad Mini";
            case "iPad4,4":
            case"iPad4,5":
            case"iPad4,6":
                return "iPad Mini 2";
            case "iPad4,7":
            case"iPad4,8":
            case"iPad4,9":
                return "iPad Mini 3";
            case"iPad5,1":
            case"iPad5,2":
                return "iPad Mini 4";
            case"iPad6,7" :
            case "iPad6,8":
                return "iPad Pro";
            case"AppleTV5,3":
                return "Apple TV";
            case"i386" :
            case "x86_64":
                return "Simulator";
            default:
                return $product;
        }
    }


}