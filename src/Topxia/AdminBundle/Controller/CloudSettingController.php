<?php

namespace Topxia\AdminBundle\Controller;

use Imagine\Image\Box;
use Imagine\Gd\Imagine;
use Topxia\Common\FileToolkit;
use Topxia\Service\CloudPlatform\KeyApplier;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Topxia\Service\CloudPlatform\CloudAPIFactory;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class CloudSettingController extends BaseController
{
    public function keyAction(Request $request)
    {
        $settings = $this->getSettingService()->get('storage', array());

        if (empty($settings['cloud_access_key']) || empty($settings['cloud_secret_key'])) {
            return $this->redirect($this->generateUrl('admin_setting_cloud_key_update'));
        }

        return $this->render('TopxiaAdminBundle:CloudSetting:key.html.twig', array(
        ));
    }

    public function keyInfoAction(Request $request)
    {
        $api  = CloudAPIFactory::create('root');
        $info = $api->get('/me');

        if (!empty($info['accessKey'])) {
            $settings = $this->getSettingService()->get('storage', array());

            if (empty($settings['cloud_key_applied'])) {
                $settings['cloud_key_applied'] = 1;
                $this->getSettingService()->set('storage', $settings);
            }

            $this->refreshCopyright($info);
        } else {
            $settings                      = $this->getSettingService()->get('storage', array());
            $settings['cloud_key_applied'] = 0;
            $this->getSettingService()->set('storage', $settings);
        }

        $currentHost = $request->server->get('HTTP_HOST');

        if (isset($info['licenseDomains'])) {
            $info['licenseDomainCount'] = count(explode(';', $info['licenseDomains']));
        }

        return $this->render('TopxiaAdminBundle:CloudSetting:key-license-info.html.twig', array(
            'info'           => $info,
            'currentHost'    => $currentHost,
            'isLocalAddress' => $this->isLocalAddress($currentHost)
        ));
    }

    public function keyBindAction(Request $request)
    {
        $api         = CloudAPIFactory::create('root');
        $currentHost = $request->server->get('HTTP_HOST');
        $result      = $api->post('/me/license-domain', array('domain' => $currentHost));

        if (!empty($result['licenseDomains'])) {
            $this->setFlashMessage('success', '授权域名绑定成功！');
        } else {
            $this->setFlashMessage('danger', '授权域名绑定失败，请重试！');
        }

        return $this->createJsonResponse($result);
    }

    public function keyUpdateAction(Request $request)
    {
        if ($this->getWebExtension()->isTrial()) {
            return $this->redirect($this->generateUrl('admin_setting_cloud_key'));
        }

        $settings = $this->getSettingService()->get('storage', array());

        if ($request->getMethod() == 'POST') {
            $options = $request->request->all();

            $api = CloudAPIFactory::create('root');
            $api->setKey($options['accessKey'], $options['secretKey']);

            $result = $api->post(sprintf('/keys/%s/verification', $options['accessKey']));

            if (isset($result['error'])) {
                $this->setFlashMessage('danger', 'AccessKey / SecretKey　不正确！');
                goto render;
            }

            $user = $api->get('/me');

            if ($user['edition'] != 'opensource') {
                $this->setFlashMessage('danger', 'AccessKey / SecretKey　不正确！！');
                goto render;
            }

            $settings['cloud_access_key']  = $options['accessKey'];
            $settings['cloud_secret_key']  = $options['secretKey'];
            $settings['cloud_key_applied'] = 1;

            $this->getSettingService()->set('storage', $settings);

            $this->setFlashMessage('success', '授权码保存成功！');
            return $this->redirect($this->generateUrl('admin_setting_cloud_key'));
        }

        render:
        return $this->render('TopxiaAdminBundle:CloudSetting:key-update.html.twig', array(
        ));
    }

    public function keyApplyAction(Request $request)
    {
        $applier = new KeyApplier();
        $keys    = $applier->applyKey($this->getCurrentUser());

        if (empty($keys['accessKey']) || empty($keys['secretKey'])) {
            return $this->createJsonResponse(array('error' => 'Key生成失败，请检查服务器网络后，重试！'));
        }

        $settings = $this->getSettingService()->get('storage', array());

        $settings['cloud_access_key']  = $keys['accessKey'];
        $settings['cloud_secret_key']  = $keys['secretKey'];
        $settings['cloud_key_applied'] = 1;

        $this->getSettingService()->set('storage', $settings);

        return $this->createJsonResponse(array('status' => 'ok'));
    }

    public function keyCopyrightAction(Request $request)
    {
        $api  = CloudAPIFactory::create('leaf');
        $info = $api->get('/me');

        if (empty($info['copyright'])) {
            throw $this->createAccessDeniedException('您无权操作!');
        }

        $name = $request->request->get('name');

        $this->getSettingService()->set('copyright', array(
            'owned'          => 1,
            'name'           => $request->request->get('name', ''),
            'thirdCopyright' => isset($info['thirdCopyright']) && $info['thirdCopyright'] == '1' ? 1 : 0
        ));

        return $this->createJsonResponse(array('status' => 'ok'));
    }

    public function videoAction(Request $request)
    {
        $storageSetting = $this->getSettingService()->get('storage', array());
        $default        = array(
            'upload_mode'                 => 'local',
            'cloud_bucket'                => '',
            'video_quality'               => 'low',
            'video_audio_quality'         => 'low',
            'video_watermark'             => 0,
            'video_watermark_image'       => '',
            'video_embed_watermark_image' => '',
            'video_watermark_position'    => 'topright',
            'video_fingerprint'           => 0,
            'video_header'                => null
        );

        if ($request->getMethod() == 'POST') {
            $set                 = $request->request->all();
            $set['cloud_bucket'] = trim($set['cloud_bucket']);

            $storageSetting = array_merge($default, $storageSetting, $set);
            $this->getSettingService()->set('storage', $storageSetting);
            $this->setFlashMessage('success', '云视频设置已保存！');
        } else {
            $storageSetting = array_merge($default, $storageSetting);
        }

        //云端视频判断
        $api  = CloudAPIFactory::create('root');
        $info = $api->get('/me');
        // $content   = $api->get("/user/center/{$api->getAccessKey()}/overview");
        $content   = $this->getContent();
        $videoInfo = $content['vlseInfo']['videoInfo'];

        $headLeader = array();

        if (!empty($storageSetting) && array_key_exists("video_header", $storageSetting) && $storageSetting["video_header"]) {
            $headLeader = $this->getUploadFileService()->getFileByTargetType('headLeader');
        }

        return $this->render('TopxiaAdminBundle:CloudSetting:video.html.twig', array(
            'storageSetting' => $storageSetting,
            'headLeader'     => $headLeader,
            'videoInfo'      => $videoInfo,
            'info'           => $info
        ));
    }

    //此方法做测试用，离线数据
    public function getContent()
    {
        $content             = array();
        $content['cashInfo'] = array(
            'cash'          => '13737',
            'arrearageDays' => '230'
        );
        $content['couponInfo'] = array(
            'availableMoney' => '99.00'
        );
        $vlseInfo              = array();
        $vlseInfo['videoInfo'] = array(
            'userId'         => '13737',
            'startMouth'     => '201512',
            'endMouth'       => '201611',
            'freeTransfer'   => '100.00',
            'freeSpace'      => '100.00',
            'amount'         => '24.00',
            'enableBuyVideo' => 1,
            'renewVideo'     => array(
                'userId'        => '13737',
                'effectiveDate' => '1480521600'
            ),
            'videoBill'      => null,
            'firstday'       => '1448899200',
            'lastday'        => '1480435200',
            'remaining'      => 337,
            'tlp'            => '0',
            'usedInfo'       => array(
                '2015-12-22' => '7',
                '2015-12-23' => '9',
                '2015-12-24' => '75',
                '2015-12-25' => '89',
                '2015-12-26' => '13',
                '2015-12-27' => '8',
                '2015-12-28' => '9'
            )

        );
        $vlseInfo['liveInfo'] = array(
            'userId'      => '13737',
            'capacity'    => '100',
            'expire'      => '1453478400',
            'renewInfo'   => array('effectiveDate' => '1453564800'),
            'upgradeInfo' => array(),
            'usedInfo'    => array(
                '2015-12-22' => '37',
                '2015-12-23' => '9',
                '2015-12-24' => '55',
                '2015-12-25' => '69',
                '2015-12-26' => '19',
                '2015-12-27' => '86',
                '2015-12-28' => '84'
            )

        );
        $vlseInfo['smsInfo'] = array(
            'remainCount' => '2000',
            'sttaus'      => 'used',
            'usedInfo'    => array(
                '2015-12-22' => '47',
                '2015-12-23' => '95',
                '2015-12-24' => '65',
                '2015-12-25' => '9',
                '2015-12-26' => '18',
                '2015-12-27' => '89',
                '2015-12-28' => '86'
            )
        );
        $content['vlseInfo'] = $vlseInfo;
        return $content;
    }

    public function videoWatermarkUploadAction(Request $request)
    {
        $file = $request->files->get('watermark');

        if (!FileToolkit::isImageFile($file)) {
            throw $this->createAccessDeniedException('图片格式不正确！');
        }

        $filename = 'watermark_'.time().'.'.$file->getClientOriginalExtension();

        $directory = "{$this->container->getParameter('topxia.upload.public_directory')}/system";
        $file      = $file->move($directory, $filename);
        $path      = "system/{$filename}";

        $response = array(
            'path' => $path,
            'url'  => $this->get('topxia.twig.web_extension')->getFileUrl($path)
        );

        return new Response(json_encode($response));
    }

    public function videoEmbedWatermarkUploadAction(Request $request)
    {
        $file = $request->files->get('watermark');

        if (!FileToolkit::isImageFile($file)) {
            throw $this->createAccessDeniedException('图片格式不正确！');
        }

        $filename = 'watermarkembed_'.time().'.'.$file->getClientOriginalExtension();

        $directory      = "{$this->container->getParameter('topxia.upload.public_directory')}/system";
        $file           = $file->move($directory, $filename);
        $path           = "system/{$filename}";
        $originFileInfo = getimagesize($file);
        $filePath       = $this->container->getParameter('topxia.upload.public_directory')."/".$path;
        $imagine        = new Imagine();
        $rawImage       = $imagine->open($filePath);

        $pathinfo              = pathinfo($filePath);
        $specification['240']  = 20;
        $specification['360']  = 30;
        $specification['480']  = 40;
        $specification['720']  = 60;
        $specification['1080'] = 90;

        foreach ($specification as $key => $value) {
            $width        = ($originFileInfo[0] * $value / $originFileInfo[1]);
            $specialImage = $rawImage->copy();
            $specialImage->resize(new Box($width, $value));
            $filePath = "{$pathinfo['dirname']}/{$pathinfo['filename']}-{$key}.{$pathinfo['extension']}";
            $specialImage->save($filePath);
        }

        $response = array(
            'path' => $path,
            'url'  => $this->get('topxia.twig.web_extension')->getFileUrl($path)
        );

        return new Response(json_encode($response));
    }

    public function videoWatermarkRemoveAction(Request $request)
    {
        return $this->createJsonResponse(true);
    }

    protected function isLocalAddress($address)
    {
        if (in_array($address, array('localhost', '127.0.0.1'))) {
            return true;
        }

        if (strpos($address, '192.168.') === 0) {
            return true;
        }

        if (strpos($address, '10.') === 0) {
            return true;
        }

        return false;
    }

    protected function getSettingService()
    {
        return $this->getServiceKernel()->createService('System.SettingService');
    }

    protected function getAppService()
    {
        return $this->getServiceKernel()->createService('CloudPlatform.AppService');
    }

    protected function getUploadFileService()
    {
        return $this->getServiceKernel()->createService('File.UploadFileService');
    }

    private function getWebExtension()
    {
        return $this->container->get('topxia.twig.web_extension');
    }
}
