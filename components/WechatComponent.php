<?php
namespace xfstudio\wechat\components;

use yii\base\Object;

/**
 * 微信
 * @package xfstudio\wechat\components
 */
class WechatComponent extends Object
{
    /**
     * @var BaseWechat
     */
    protected $wechat;

    /**
     * @param BaseWechat $wechat
     * @param array $config
     */
    public function __construct(BaseWechat $wechat, $config = [])
    {
        $this->wechat = $wechat;
        parent::__construct($config);
    }
}