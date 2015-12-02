<?php
namespace xfstudio\wechat;

use Yii;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use xfstudio\wechat\components\BaseWechat;
use xfstudio\wechat\components\MessageCrypt;
/**
 * 微信企业号操作SDK
 * @package calmez\wechat\sdk
 */
class QyWechat extends BaseWechat
{
    /**
     * 微信接口基本地址
     */
    const WECHAT_BASE_URL = 'https://qyapi.weixin.qq.com';
    /**
     * 数据缓存前缀
     * @var string
     */
    public $cachePrefix = 'cache_wechat_sdk_qy';
    /**
     * 企业号的唯一标识
     * @var string
     */
    public $corpId;
    /**
     * 管理组凭证密钥
     * @var string
     */
    public $corpSecret;
    /**
     * 公众号接口验证token,可由您来设定. 并填写在微信公众平台->开发者中心
     * @var string
     */
    public $token;
    /**
     * 公众号消息加密键值
     * @var string
     */
    public $encodingAesKey;

    public $access_token;
    public $agentid;       //应用id   AgentID
    public $postxml;
    public $agentidxml;    //接收的应用id   AgentID
    public $_msg;
    public $_receive;
    public $_sendmsg;      //主动发送消息的内容
    public $_text_filter = true;
    public $_logcallback;
    public $token_expire=7000;

    public $debug =  false;
    public $errCode = 40001;
    public $errMsg = "no access";

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function setParameter($options)
    {
        $this->token = isset($options['token'])?$options['token']:'';
        $this->encodingAesKey = isset($options['encodingAesKey'])?$options['encodingAesKey']:'';
        $this->corpId = isset($options['corpId'])?$options['corpId']:'';
        $this->corpSecret = isset($options['corpSecret'])?$options['corpSecret']:'';
        $this->agentid = isset($options['agentid'])?$options['agentid']:'';
        $this->debug = isset($options['debug'])?$options['debug']:false;
        $this->_logcallback = isset($options['logcallback'])?$options['logcallback']:false;

        if (empty($this->corpId)) {
            throw new InvalidConfigException('The "corpId" property must be set.');
        } elseif (empty($this->corpSecret)) {
            throw new InvalidConfigException('The "corpSecret" property must be set.');
        } elseif (empty($this->token)) {
            throw new InvalidConfigException('The "token" property must be set.');
        } elseif (empty($this->encodingAesKey)) {
            throw new InvalidConfigException('The "encodingAesKey" property must be set.');
        }
    }

    /**
     * 数据XML编码
     * @param mixed $data 数据
     * @return string
     */
    public static function data_to_xml($data) {
        $xml = '';
        foreach ($data as $key => $val) {
            is_numeric($key) && $key = "item id=\"$key\"";
            $xml    .=  "<$key>";
            $xml    .=  ( is_array($val) || is_object($val)) ? self::data_to_xml($val)  : self::xmlSafeStr($val);
            list($key, ) = explode(' ', $key);
            $xml    .=  "</$key>";
        }
        return $xml;
    }

    public static function xmlSafeStr($str)
    {
        return '<![CDATA['.preg_replace("/[\\x00-\\x08\\x0b-\\x0c\\x0e-\\x1f]/",'',$str).']]>';
    }

    /**
     * XML编码
     * @param mixed $data 数据
     * @param string $root 根节点名
     * @param string $item 数字索引的子节点名
     * @param string $attr 根节点属性
     * @param string $id   数字索引子节点key转换的属性名
     * @param string $encoding 数据编码
     * @return string
     */
    public function xml_encode($data, $root='xml', $item='item', $attr='', $id='id', $encoding='utf-8') {
        if(is_array($attr)){
            $_attr = array();
            foreach ($attr as $key => $value) {
                $_attr[] = "{$key}=\"{$value}\"";
            }
            $attr = implode(' ', $_attr);
        }
        $attr   = trim($attr);
        $attr   = empty($attr) ? '' : " {$attr}";
        $xml   = "<{$root}{$attr}>";
        $xml   .= self::data_to_xml($data, $item, $id);
        $xml   .= "</{$root}>";
        return $xml;
    }

    /**
     * 微信api不支持中文转义的json结构
     * @param array $arr
     */
    static function json_encode($arr) {
        $parts = array ();
        $is_list = false;
        //Find out if the given array is a numerical array
        $keys = array_keys ( $arr );
        $max_length = count ( $arr ) - 1;
        if (($keys [0] === 0) && ($keys [$max_length] === $max_length )) { //See if the first key is 0 and last key is length - 1
            $is_list = true;
            for($i = 0; $i < count ( $keys ); $i ++) { //See if each key correspondes to its position
                if ($i != $keys [$i]) { //A key fails at position check.
                    $is_list = false; //It is an associative array.
                    break;
                }
            }
        }
        foreach ( $arr as $key => $value ) {
            if (is_array ( $value )) { //Custom handling for arrays
                if ($is_list)
                    $parts [] = self::json_encode ( $value ); /* :RECURSION: */
                else
                    $parts [] = '"' . $key . '":' . self::json_encode ( $value ); /* :RECURSION: */
            } else {
                $str = '';
                if (! $is_list)
                    $str = '"' . $key . '":';
                //Custom handling for multiple data types
                if (is_numeric ( $value ) && $value<2000000000)
                    $str .= $value; //Numbers
                elseif ($value === false)
                $str .= 'false'; //The booleans
                elseif ($value === true)
                $str .= 'true';
                else
                    $str .= '"' . addslashes ( $value ) . '"'; //All other things
                // :TODO: Is there any more datatype we should be in the lookout for? (Object?)
                $parts [] = $str;
            }
        }
        $json = implode ( ',', $parts );
        if ($is_list)
            return '[' . $json . ']'; //Return numerical JSON
        return '{' . $json . '}'; //Return associative JSON
    }

    /**
     * 过滤文字回复\r\n换行符
     * @param string $text
     * @return string|mixed
     */
    public function _auto_text_filter($text) {
        if (!$this->_text_filter) return $text;
        return str_replace("\r\n", "\n", $text);
    }

    /* =================== 企业号会话服务 =================== */
    /**
     * 创建会话
     */
    const WECHAT_CHAT_CREATE_PREFIX = 'cgi-bin/chat/create';
    /**
     * [chatCreate description]
     * @param  [Array] $chatinfo [description]
     * @return [boolen]           [description]
     */
    public function chatCreate($chatinfo) {
        $result = $this->httpPost(self::WECHAT_MENU_DELETE_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'chatid' => $chatinfo['chatid'],
            'name' => $chatinfo['name'],
            'owner' => $chatinfo['owner'],
            'userlist' => $chatinfo['userlist'],
        ]);
        return ($result['errmsg'] == 'ok') ? true : $result['errmsg'];
    }

    /**
     * 获取会话
     */
    const WECHAT_CHAT_GET_PREFIX = 'cgi-bin/chat/get';
    /**
     * [chatGet description]
     * @param  [String] $chatid [description]
     * @return [Array|String]         [description]
     */
    public function chatGet($chatid) {
        $result = $this->httpGet(self::WECHAT_MENU_DELETE_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'chatid' => $chatid,
        ]);
        return ($result['errmsg'] == 'ok') ? $result['chat_info'] : $result['errmsg'];
    }

    /**
     * 退出会话
     */
    const WECHAT_CHAT_QUIT_PREFIX = 'cgi-bin/chat/quit';
    /**
     * [chatQuit description]
     * @param  [type] $chatid  [description]
     * @param  [type] $op_user [description]
     * @return [type]          [description]
     */
    public function chatQuit($chatid, $op_user) {
        $result = $this->httpPost(self::WECHAT_CHAT_QUIT_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'chatid' => $chatid,
            'op_user' => $op_user,
        ]);
        return ($result['errmsg'] == 'ok') ? true : $result['errmsg'];
    }

    /**
     * 清除会话未读状态
     */
    const WECHAT_CHAT_CLEARNOTIFY_PREFIX = 'cgi-bin/chat/clearnotify';
    /**
     * [chatClearNotify description]
     * @param  [Array] $chat  [description]
     * @param  [type] $op_user [description]
     * @return [type]          [description]
     */
    public function chatClearNotify($chat, $op_user) {
        $result = $this->httpPost(self::WECHAT_CHAT_CLEARNOTIFY_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'chat' => json_encode($chat),
            'op_user' => $op_user,
        ]);
        return ($result['errmsg'] == 'ok') ? true : $result['errmsg'];
    }

    /**
     * 发送消息
     */
    const WECHAT_CHAT_SEND_PREFIX = 'cgi-bin/chat/send';
    /**
     * [chatSend description]
     * @param  [Array] $receiver  [ {"type": "group", "id": "235364212115767297"}   ]
     * @param  [String] $op_user [description]
     * @return [type]          [description]
     */
    public function chatSend($sender, $receiver, $content, $type = 'text') {
        $result = $this->httpPost(self::WECHAT_CHAT_SEND_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'receiver' => json_encode($receiver),
            'sender' => $sender,
            'msgtype' => $type,
            $type => json_encode($content),
        ]);
        return ($result['errmsg'] == 'ok') ? true : $result['errmsg'];
    }

    /**
     * 设置成员新消息免打扰
     */
    const WECHAT_CHAT_SETMUTE_PREFIX = 'cgi-bin/chat/setmute';
    /**
     * [chatSetMute description]
     * @param  [Array] $mutelist  [ {"type": "group", "id": "235364212115767297"}   ]
     * @param  [String] $op_user [description]
     * @return [type]          [description]
     */
    public function chatSetMute($mutelist) {
        $result = $this->httpPost(self::WECHAT_CHAT_SETMUTE_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'receiver' => json_encode($mutelist),
        ]);
        return ($result['errmsg'] == 'ok') ? true : $result['errmsg'];
    }


    /* =================== 企业号信息接受和回复 =================== */
    /**
     * For weixin server validation
     */
    public function checkSignature($str)
    {
        $signature = isset($_GET["msg_signature"])?$_GET["msg_signature"]:'';
        $timestamp = isset($_GET["timestamp"])?$_GET["timestamp"]:'';
        $nonce = isset($_GET["nonce"])?$_GET["nonce"]:'';
        $tmpArr = array($str,$this->token, $timestamp, $nonce);//比普通公众平台多了一个加密的密文
        sort($tmpArr, SORT_STRING);
        $tmpStr = implode($tmpArr);
        $shaStr = sha1($tmpStr);
        if( $shaStr == $signature ){
            return true;
        }else{
            return false;
        }
    }

    /**
     * 微信验证，包括post来的xml解密
     * @param bool $return 是否返回
     */
    public function valid($return=false)
    {
        $encryptStr="";
        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $postStr = file_get_contents("php://input");
            $array = (array)simplexml_load_string($postStr, 'SimpleXMLElement', LIBXML_NOCDATA);
            $this->log($postStr);
            if (isset($array['Encrypt'])){
                $encryptStr = $array['Encrypt'];
                $this->agentidxml = isset($array['AgentID']) ? $array['AgentID']: '';
            }
        } else {
            $encryptStr = isset($_GET["echostr"]) ? $_GET["echostr"]: '';

        }
        if ($encryptStr) {
            $ret=$this->checkSignature($encryptStr);
        }
        if (!isset($ret) || !$ret) {
            if (!$return) {
                die('no access');
            } else {
                return false;
            }
        }
        $pc = new Prpcrypt($this->encodingAesKey);
        $array = $pc->decrypt($encryptStr,$this->appid);
        if (!isset($array[0]) || ($array[0] != 0)) {
            if (!$return) {
                die('解密失败！');
            } else {
                return false;
            }
        }
        if ($_SERVER['REQUEST_METHOD'] == "POST") {
            $this->postxml = $array[1];
            //$this->log($array[1]);
            return ($this->postxml!="");
        } else {
            $echoStr = $array[1];
            if ($return) {
                return $echoStr;
            } else {
                die($echoStr);
            }
        }
        return false;
    }

    /**
     * 获取微信服务器发来的信息
     */
    public function getRev()
    {
        if ($this->_receive) return $this;
        $postStr = $this->postxml;
        $this->log($postStr);
        if (!empty($postStr)) {
            $this->_receive = (array)simplexml_load_string($postStr, 'SimpleXMLElement', LIBXML_NOCDATA);
            if (!isset($this->_receive['AgentID'])) {
                 $this->_receive['AgentID']=$this->agentidxml; //当前接收消息的应用id
            }
        }
        return $this;
    }

    /**
     * 获取微信服务器发来的信息
     */
    public function getRevData()
    {
        return $this->_receive;
    }

    /**
     * 获取微信服务器发来的原始加密信息
     */
    public function getRevPostXml()
    {
        return $this->postxml;
    }

    /**
     * 获取消息发送者
     */
    public function getRevFrom() {
        if (isset($this->_receive['FromUserName']))
            return $this->_receive['FromUserName'];
        else
            return false;
    }

    /**
     * 获取消息接受者
     */
    public function getRevTo() {
        if (isset($this->_receive['ToUserName']))
            return $this->_receive['ToUserName'];
        else
            return false;
    }

    /**
     * 获取接收消息的应用id
     */
    public function getRevAgentID() {
        if (isset($this->_receive['AgentID']))
            return $this->_receive['AgentID'];
        else
            return false;
    }

    /**
     * 获取接收消息的类型
     */
    public function getRevType() {
        if (isset($this->_receive['MsgType']))
            return $this->_receive['MsgType'];
        else
            return false;
    }

    /**
     * 获取消息ID
     */
    public function getRevID() {
        if (isset($this->_receive['MsgId']))
            return $this->_receive['MsgId'];
        else
            return false;
    }

    /**
     * 获取消息发送时间
     */
    public function getRevCtime() {
        if (isset($this->_receive['CreateTime']))
            return $this->_receive['CreateTime'];
        else
            return false;
    }

    /**
     * 获取接收消息内容正文
     */
    public function getRevContent(){
        if (isset($this->_receive['Content']))
            return $this->_receive['Content'];
        else
            return false;
    }

    /**
     * 获取接收消息图片
     */
    public function getRevPic(){
        if (isset($this->_receive['PicUrl']))
            return array(
                'mediaid'=>$this->_receive['MediaId'],
                'picurl'=>(string)$this->_receive['PicUrl'],    //防止picurl为空导致解析出错
            );
        else
            return false;
    }

    /**
     * 获取接收地理位置
     */
    public function getRevGeo(){
        if (isset($this->_receive['Location_X'])){
            return array(
                'x'=>$this->_receive['Location_X'],
                'y'=>$this->_receive['Location_Y'],
                'scale'=>(string)$this->_receive['Scale'],
                'label'=>(string)$this->_receive['Label']
            );
        } else
            return false;
    }

    /**
     * 获取上报地理位置事件
     */
    public function getRevEventGeo(){
            if (isset($this->_receive['Latitude'])){
                 return array(
                'x'=>$this->_receive['Latitude'],
                'y'=>$this->_receive['Longitude'],
                'precision'=>$this->_receive['Precision'],
            );
        } else
            return false;
    }

    /**
     * 获取接收事件推送
     */
    public function getRevEvent(){
        if (isset($this->_receive['Event'])){
            $array['event'] = $this->_receive['Event'];
        }
        if (isset($this->_receive['EventKey'])){
            $array['key'] = $this->_receive['EventKey'];
        }
        if (isset($array) && count($array) > 0) {
            return $array;
        } else {
            return false;
        }
    }

    /**
     * 获取自定义菜单的扫码推事件信息
     *
     * 事件类型为以下两种时则调用此方法有效
     * Event     事件类型，scancode_push
     * Event     事件类型，scancode_waitmsg
     *
     * @return: array | false
     * array (
     *     'ScanType'=>'qrcode',
     *     'ScanResult'=>'123123'
     * )
     */
    public function getRevScanInfo(){
        if (isset($this->_receive['ScanCodeInfo'])){
            if (!is_array($this->_receive['SendPicsInfo'])) {
                $array=(array)$this->_receive['ScanCodeInfo'];
                $this->_receive['ScanCodeInfo']=$array;
            }else {
                $array=$this->_receive['ScanCodeInfo'];
            }
        }
        if (isset($array) && count($array) > 0) {
            return $array;
        } else {
            return false;
        }
    }

    /**
     * 获取自定义菜单的图片发送事件信息
     *
     * 事件类型为以下三种时则调用此方法有效
     * Event     事件类型，pic_sysphoto        弹出系统拍照发图的事件推送
     * Event     事件类型，pic_photo_or_album  弹出拍照或者相册发图的事件推送
     * Event     事件类型，pic_weixin          弹出微信相册发图器的事件推送
     *
     * @return: array | false
     * array (
     *   'Count' => '2',
     *   'PicList' =>array (
     *         'item' =>array (
     *             0 =>array ('PicMd5Sum' => 'aaae42617cf2a14342d96005af53624c'),
     *             1 =>array ('PicMd5Sum' => '149bd39e296860a2adc2f1bb81616ff8'),
     *         ),
     *   ),
     * )
     *
     */
    public function getRevSendPicsInfo(){
        if (isset($this->_receive['SendPicsInfo'])){
            if (!is_array($this->_receive['SendPicsInfo'])) {
                $array=(array)$this->_receive['SendPicsInfo'];
                if (isset($array['PicList'])){
                    $array['PicList']=(array)$array['PicList'];
                    $item=$array['PicList']['item'];
                    $array['PicList']['item']=array();
                    foreach ( $item as $key => $value ){
                        $array['PicList']['item'][$key]=(array)$value;
                    }
                }
                $this->_receive['SendPicsInfo']=$array;
            } else {
                $array=$this->_receive['SendPicsInfo'];
            }
        }
        if (isset($array) && count($array) > 0) {
            return $array;
        } else {
            return false;
        }
    }

    /**
     * 获取自定义菜单的地理位置选择器事件推送
     *
     * 事件类型为以下时则可以调用此方法有效
     * Event     事件类型，location_select        弹出系统拍照发图的事件推送
     *
     * @return: array | false
     * array (
     *   'Location_X' => '33.731655000061',
     *   'Location_Y' => '113.29955200008047',
     *   'Scale' => '16',
     *   'Label' => '某某市某某区某某路',
     *   'Poiname' => '',
     * )
     *
     */
    public function getRevSendGeoInfo(){
        if (isset($this->_receive['SendLocationInfo'])){
            if (!is_array($this->_receive['SendLocationInfo'])) {
                $array=(array)$this->_receive['SendLocationInfo'];
                if (empty($array['Poiname'])) {
                    $array['Poiname']="";
                }
                if (empty($array['Label'])) {
                    $array['Label']="";
                }
                $this->_receive['SendLocationInfo']=$array;
            } else {
                $array=$this->_receive['SendLocationInfo'];
            }
        }
        if (isset($array) && count($array) > 0) {
            return $array;
        } else {
            return false;
        }
    }
    /**
     * 获取接收语音推送
     */
    public function getRevVoice(){
        if (isset($this->_receive['MediaId'])){
            return array(
                'mediaid'=>$this->_receive['MediaId'],
                'format'=>$this->_receive['Format'],
            );
        } else
            return false;
    }

    /**
     * 获取接收视频推送
     */
    public function getRevVideo(){
        if (isset($this->_receive['MediaId'])){
            return array(
                    'mediaid'=>$this->_receive['MediaId'],
                    'thumbmediaid'=>$this->_receive['ThumbMediaId']
            );
        } else
            return false;
    }

    /**
     * 设置回复文本消息
     * Examle: $obj->text('hello')->reply();
     * @param string $text
     */
    public function text($text='')
    {
        $msg = array(
            'ToUserName' => $this->getRevFrom(),
            'FromUserName'=>$this->getRevTo(),
            'MsgType'=>self::MSGTYPE_TEXT,
            'Content'=>$this->_auto_text_filter($text),
            'CreateTime'=>time(),
        );
        $this->Message($msg);
        return $this;
    }

    /**
     * 设置回复图片消息
     * Examle: $obj->image('media_id')->reply();
     * @param string $mediaid
     */
    public function image($mediaid='')
    {
        $msg = array(
            'ToUserName' => $this->getRevFrom(),
            'FromUserName'=>$this->getRevTo(),
            'MsgType'=>self::MSGTYPE_IMAGE,
            'Image'=>array('MediaId'=>$mediaid),
            'CreateTime'=>time(),
        );
        $this->Message($msg);
        return $this;
    }

    /**
     * 设置回复语音消息
     * Examle: $obj->voice('media_id')->reply();
     * @param string $mediaid
     */
    public function voice($mediaid='')
    {
        $msg = array(
            'ToUserName' => $this->getRevFrom(),
            'FromUserName'=>$this->getRevTo(),
            'MsgType'=>self::MSGTYPE_IMAGE,
            'Voice'=>array('MediaId'=>$mediaid),
            'CreateTime'=>time(),
        );
        $this->Message($msg);
        return $this;
    }
    /**
     * 设置回复视频消息
     * Examle: $obj->video('media_id','title','description')->reply();
     * @param string $mediaid
     */
    public function video($mediaid='',$title,$description)
    {
        $msg = array(
            'ToUserName' => $this->getRevFrom(),
            'FromUserName'=>$this->getRevTo(),
            'MsgType'=>self::MSGTYPE_IMAGE,
            'Video'=>array(
                    'MediaId'=>$mediaid,
                    'Title'=>$mediaid,
                    'Description'=>$mediaid,
            ),
            'CreateTime'=>time(),
        );
        $this->Message($msg);
        return $this;
    }
    /**
     * 设置回复图文
     * @param array $newsData
     * 数组结构:
     *  array(
     *      "0"=>array(
     *          'Title'=>'msg title',
     *          'Description'=>'summary text',
     *          'PicUrl'=>'http://www.domain.com/1.jpg',
     *          'Url'=>'http://www.domain.com/1.html'
     *      ),
     *      "1"=>....
     *  )
     */
    public function news($newsData=array())
    {

        $count = count($newsData);
        $msg = array(
            'ToUserName' => $this->getRevFrom(),
            'FromUserName'=>$this->getRevTo(),
            'MsgType'=>self::MSGTYPE_NEWS,
            'CreateTime'=>time(),
            'ArticleCount'=>$count,
            'Articles'=>$newsData,

        );
        $this->Message($msg);
        return $this;
    }
    /**
     * 设置发送消息
     * @param array $msg 消息数组
     * @param bool $append 是否在原消息数组追加
     */
    public function Message($msg = '',$append = false){
        if (is_null($msg)) {
            $this->_msg =array();
        }elseif (is_array($msg)) {
            if ($append)
                $this->_msg = array_merge($this->_msg,$msg);
            else
                $this->_msg = $msg;
            return $this->_msg;
        } else {
            return $this->_msg;
        }
    }

    /**
     *
     * 回复微信服务器, 此函数支持链式操作
     * Example: $this->text('msg tips')->reply();
     * @param string $msg 要发送的信息, 默认取$this->_msg
     * @param bool $return 是否返回信息而不抛出到浏览器 默认:否
     */
    public function reply($msg=array(),$return = false)
    {
        if (empty($msg))
            $msg = $this->_msg;
        $xmldata=  $this->xml_encode($msg);
        $this->log($xmldata);
        $pc = new Prpcrypt($this->encodingAesKey);
        $array = $pc->encrypt($xmldata, $this->appid);
        $ret = $array[0];
        if ($ret != 0) {
            $this->log('encrypt err!');
            return false;
        }
        $timestamp = time();
        $nonce = rand(77,999)*rand(605,888)*rand(11,99);
        $encrypt = $array[1];
        $tmpArr = array($this->token, $timestamp, $nonce,$encrypt);//比普通公众平台多了一个加密的密文
        sort($tmpArr, SORT_STRING);
        $signature = implode($tmpArr);
        $signature = sha1($signature);
        $smsg = $this->generate($encrypt, $signature, $timestamp, $nonce);
        // $this->log($smsg);
        if ($return)
            return $smsg;
        elseif ($smsg){
            echo $smsg;
            return true;
        }else
            return false;
    }

    public function generate($encrypt, $signature, $timestamp, $nonce)
    {
        //格式化加密信息
        $format = "<xml>
            <Encrypt><![CDATA[%s]]></Encrypt>
            <MsgSignature><![CDATA[%s]]></MsgSignature>
            <TimeStamp>%s</TimeStamp>
            <Nonce><![CDATA[%s]]></Nonce>
            </xml>";
        return sprintf($format, $encrypt, $signature, $timestamp, $nonce);
    }


    /**
     * 增加微信基本链接
     * @inheritdoc
     */
    protected function httpBuildQuery($url, array $options)
    {
        if (stripos($url, 'http://') === false && stripos($url, 'https://') === false) {
            $url = self::WECHAT_BASE_URL . $url;
        }
        return parent::httpBuildQuery($url, $options);
    }

    /* =================== 建立连接 =================== */

    /**
     * 请求服务器access_token
     */
    const WECHAT_ACCESS_TOKEN_PREFIX = '/cgi-bin/gettoken';
    protected function requestAccessToken()
    {
        $result = $this->httpGet(self::WECHAT_ACCESS_TOKEN_PREFIX, [
            'corpid' => $this->corpId,
            'corpsecret' => $this->corpSecret
        ]);
        return isset($result['access_token']) ? $result : false;
    }

    /**
     * access token组件安装后的access_token
     */
    const WECHAT_ACCESS_TOKEN_GET_PREFIX = '/cgi-bin/get_corp_token';
    public function getCorpToken($agentid,$user_id){
        $suite_access_token=$this->getSuiteToken($agentid,$user_id);
        $url='https://qyapi.weixin.qq.com/cgi-bin/service/get_corp_token?suite_access_token='.$suite_access_token['suite_access_token'];
        $data=M('Qymyapp')->where(array('userid'=>$user_id,'appid'=>$agentid))->find();
        $user=M('Qytoken')->where(array('id'=>$user_id))->find();
        $info['suite_id']=$data['suit_id'];
        $info['auth_corpid']=$user['th_corpid'];
        $info['permanent_code']=$user['permanent_code'];
        $result = $this->httpGet(self::WECHAT_ACCESS_TOKEN_GET_PREFIX, $info);
        return isset($result['access_token']) ? $result : false;
    }

    /**
     * access token组件套装安装后的access_token
     */
    const WECHAT_ACCESS_TOKEN_SUITE_PREFIX = '/cgi-bin/get_corp_token';
    public function getSuiteToken($agentid,$user_id){
        $url='https://qyapi.weixin.qq.com/cgi-bin/service/get_suite_token';
        $app=M('Qymyapp')->where(array('userid'=>$user_id,'appid'=>$agentid))->field('suit_id')->find();
        $data=M('Suiteid')->where(array('suiteid'=>$app['suit_id']))->find();
        $info['suite_id']=$data['suiteid'];
        $info['suite_secret']=$data['su_secret'];
        $info['suite_ticket']=$data['suiteticket'];
        $result = $this->httpGet(self::WECHAT_ACCESS_TOKEN_SUITE_PREFIX, $info);
        return isset($result['suite_access_token']) ? $result : false;
    }

    /**
     * 获取微信服务器IP地址
     */
    const WECHAT_IP_PREFIX = '/cgi-bin/getcallbackip';
    /**
     * 获取微信服务器IP地址
     * @return array|bool
     * @throws \yii\web\HttpException
     */
    public function getIp()
    {
        $result = $this->httpGet(self::WECHAT_IP_PREFIX, [
            'access_token' => $this->getAccessToken()
        ]);
        return isset($result['ip_list']) ? $result['ip_list'] : false;
    }

    /* =================== 管理通讯录 =================== */

    /**
     * 通用auth验证方法
     * @param string $appid
     * @param string $appcorpSecret
     * @param string $token 手动指定access_token，非必要情况不建议用
     */
    public function checkAuth($appid='',$appcorpSecret='',$token=''){
        if (!$appid || !$appcorpSecret) {
            $appid = $this->appid;
            $appcorpSecret = $this->appcorpSecret;
        }
        if ($token) { //手动指定token，优先使用
            $this->access_token=$token;
            return $this->access_token;
        }
        //TODO: get the cache access_token
        if(memory('check') && ($token=memory('get','qywechat_token'))){
             $this->access_token=$token;
            return $this->access_token;
        }
        $result = $this->http_get(self::API_URL_PREFIX.self::TOKEN_GET_URL.'corpid='.$appid.'&corpcorpSecret='.$appcorpSecret);
        if ($result)
        {
            $json = json_decode($result,true);
            if (!$json || isset($json['errcode'])) {
                $this->errCode = $json['errcode'];
                $this->errMsg = $json['errmsg'];
                return false;
            }
            $this->access_token = $json['access_token'];

            $expire = $json['expires_in'] ? intval($json['expires_in'])-100 : $this->token_expire;
            //TODO: cache access_token
            memory('check') && memory('set','qywechat_token',$this->access_token,$expire);
            return $this->access_token;
        }
        return false;
    }

    /**
     * 删除验证数据
     * @param string $appid
     */
    public function resetAuth($appid=''){
        if (!$appid) $appid = $this->appid;
        $this->access_token = '';
        //TODO: remove cache
        return true;
    }

    /**
     * 二次验证
     */
    const WECHAT_USER_AUTH_SUCCESS_PREFIX = '/cgi-bin/user/authsucc';
    /**
     * 二次验证
     * @param $userId
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function userAuthSuccess($userId)
    {
        $result = $this->httpGet(self::WECHAT_USER_AUTH_SUCCESS_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'userid' => $userId
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok';
    }

    /**
     * 创建部门
     */
    const WECHAT_DEPARTMENT_CREATE_PREFIX = '/cgi-bin/department/create';
    /**
     * 创建部门
     * @param array $data
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function createDepartment(array $data)
    {
        $result = $this->httpRaw(self::WECHAT_DEPARTMENT_CREATE_PREFIX, $data, [
            'access_token' => $this->getAccessToken()
        ]);
        return isset($result['errcode']) && !$result['errcode'] ? $result['id'] : false;
    }

    /**
     * 创建部门
     */
    const WECHAT_DEPARTMENT_UPDATE_PREFIX = '/cgi-bin/department/update';
    /**
     * 创建部门
     * @param array $data
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function updateDepartment(array $data)
    {
        $result = $this->httpRaw(self::WECHAT_DEPARTMENT_CREATE_PREFIX, $data, [
            'access_token' => $this->getAccessToken()
        ]);
        return isset($result['errcode']) && !$result['errcode'];
    }

    /**
     * 删除部门
     */
    const WECHAT_DEPARTMENT_DELETE_PREFIX = '/cgi-bin/department/delete';
    /**
     * 删除部门
     * @param $id
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function deleteDepartment($id)
    {
        $result = $this->httpGet(self::WECHAT_DEPARTMENT_DELETE_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'id' => $id
        ]);
        return isset($result['errcode']) && !$result['errcode'];
    }

    /**
     * 获取部门列表
     */
    const WECHAT_DEPARTMENT_LIST = '/cgi-bin/department/list';
    /**
     * 获取部门列表
     * @param null $id 部门id。获取指定部门id下的子部门
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function getDepartmentList($id = null)
    {
        $result = $this->httpGet(self::WECHAT_DEPARTMENT_LIST, [
            'access_token' => $this->getAccessToken(),
        ] + ($id === null ? [] : [
            'id' => $id
        ]));
        // var_dump($result);die();
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result['department'] : false;
    }

    /**
     * 创建成员
     */
    const WECHAT_USER_CREATE_PREFIX = '/cgi-bin/user/create';
    /**
     * 创建成员
     * @param array $data
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function createUser(array $data)
    {
        $result = $this->httpRaw(self::WECHAT_USER_CREATE_PREFIX, $data, [
            'access_token' => $this->getAccessToken()
        ]);
        return isset($result['errcode']) && !$result['errcode'];
    }

    /**
     * 创建成员
     */
    const WECHAT_USER_UPDATE_PREFIX = '/cgi-bin/user/update';
    /**
     * 创建成员
     * @param array $data
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function updateUser(array $data)
    {
        $result = $this->httpRaw(self::WECHAT_USER_UPDATE_PREFIX, $data, [
            'access_token' => $this->getAccessToken()
        ]);
        return isset($result['errcode']) && !$result['errcode'];
    }

    /**
     * 删除成员
     */
    const WECHAT_USER_DELETE_PREFIX = '/cgi-bin/user/delete';
    /**
     * 删除成员
     * @param $userId
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function deleteUser($userId)
    {
        $result = $this->httpGet(self::WECHAT_USER_DELETE_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'userid' => $userId
        ]);
        return isset($result['errcode']) && !$result['errcode'];
    }

    /**
     * 批量删除成员
     */
    const WECHAT_USER_BATCH_DELETE_PREFIX = '/cgi-bin/user/batchdelete';
    /**
     * 批量删除成员
     * @param array $userIdList
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function batchDeleteUser(array $userIdList)
    {
        $result = $this->httpRaw(self::WECHAT_USER_BATCH_DELETE_PREFIX, [
            'useridlist' => $userIdList
        ], [
            'access_token' => $this->getAccessToken()
        ]);
        return isset($result['errcode']) && !$result['errcode'];
    }

    /**
     * 获取部门成员(详情)
     */
    const WECHAT_USER_GET_PREFIX = '/cgi-bin/user/get';
    /**
     * 获取部门成员(详情)
     * @param $userId
     * @return bool|mixed
     * @throws \yii\web\HttpException
     */
    public function getUser($userId)
    {
        $result = $this->httpGet(self::WECHAT_USER_GET_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'userid' => $userId
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result : false;
    }

    /**
     * 获取部门成员
     */
    const WECHAT_DEPARTMENT_USER_LIST_GET_PREFIX = '/cgi-bin/user/simplelist';
    /**
     * 获取部门成员
     * @param $departmentId
     * @param int $fetchChild
     * @param int $status
     * @return bool|mixed
     * @throws \yii\web\HttpException
     */
    public function getDepartmentUserList($departmentId, $fetchChild = 0, $status = 0)
    {
        $result = $this->httpGet(self::WECHAT_DEPARTMENT_USER_LIST_GET_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'department_id' => $departmentId,
            'fetch_child' => $fetchChild,
            'status' => $status,
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result['userlist'] : false;
    }

    /**
     * 获取部门成员(详情)
     */
    const WECHAT_DEPARTMENT_USERS_INFO_LIST_GET_PREFIX = '/cgi-bin/user/list';
    /**
     * 获取部门成员(详情)
     * @param $departmentId
     * @param int $fetchChild
     * @param int $status
     * @return bool|mixed
     * @throws \yii\web\HttpException
     */
    public function getDepartmentUserInfoList($departmentId, $fetchChild = 0, $status = 0)
    {
        $result = $this->httpGet(self::WECHAT_DEPARTMENT_USERS_INFO_LIST_GET_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'department_id' => $departmentId,
            'fetch_child' => $fetchChild,
            'status' => $status,
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result['userlist'] : false;
    }

    /**
     * 邀请成员关注
     */
    const WECHAT_USER_INVITE_PREFIX = '/cgi-bin/invite/send';
    /**
     * 邀请成员关注
     * @param $userId
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function inviteUser($userId)
    {
        $result = $this->httpRaw(self::WECHAT_USER_INVITE_PREFIX, [
            'userid' => $userId
        ], [
            'access_token' => $this->getAccessToken(),
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result['type'] : false;
    }

    /**
     * 创建标签
     */
    const WECHAT_TAG_CREATE_PREFIX = '/cgi-bin/tag/create';
    /**
     * 创建标签
     * @param $tagName
     * @return int|bool
     * @throws \yii\web\HttpException
     */
    public function createTag($tagName)
    {
        $result = $this->httpRaw(self::WECHAT_TAG_CREATE_PREFIX, [
            'tagname' => $tagName
        ], [
            'access_token' => $this->getAccessToken()
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result['tagid'] : false;
    }

    /**
     * 更新标签名字
     */
    const WECHAT_TAG_NAME_UPDATE_PREFIX = '/cgi-bin/tag/update';
    /**
     * 更新标签名字
     * @param $tagId
     * @param $tagName
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function updateTagName($tagId, $tagName)
    {
        $result = $this->httpRaw(self::WECHAT_TAG_CREATE_PREFIX, [
            'tagid' => $tagId,
            'tagname' => $tagName
        ], [
            'access_token' => $this->getAccessToken()
        ]);
        return isset($result['errcode']) && !$result['errcode'];
    }

    /**
     * 删除标签
     */
    const WECHAT_TAG_DELETE_PREFIX = '/cgi-bin/tag/delete';
    /**
     * 删除标签
     * @param $tagId
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function deleteTag($tagId)
    {
        $result = $this->httpGet(self::WECHAT_TAG_DELETE_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'tagid' => $tagId
        ]);
        return isset($result['errcode']) && !$result['errcode'];
    }

    /**
     * 获取标签成员
     */
    const WECHAT_TAG_USER_LIST_GET_PREFIX = '/cgi-bin/tag/get';
    /**
     * 获取标签成员
     * @param $tagId
     * @return bool|mixed
     * @throws \yii\web\HttpException
     */
    public function getTagUserList($tagId)
    {
        $result = $this->httpGet(self::WECHAT_TAG_USER_LIST_GET_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'tagid' => $tagId
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result : false;
    }

    /**
     * 增加标签成员
     */
    const WECHAT_TAG_USERS_ADD_PREFIX = '/cgi-bin/tag/addtagusers';
    /**
     * 增加标签成员
     * @param array $data
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function addTagUsers(array $data)
    {
        $result = $this->httpRaw(self::WECHAT_TAG_USERS_ADD_PREFIX, $data, [
            'access_token' => $this->getAccessToken(),
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok';
    }

    /**
     * 删除标签成员
     */
    const WECHAT_TAG_USERS_DELETE_PREFIX = '/cgi-bin/tag/deltagusers';
    /**
     * 删除标签成员
     * @param array $data
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function deleteTagUsers(array $data)
    {
        $result = $this->httpRaw(self::WECHAT_TAG_USERS_DELETE_PREFIX, $data, [
            'access_token' => $this->getAccessToken(),
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok';
    }

    /**
     * 获取标签列表
     */
    const WECHAT_TAG_LIST_GET_PREFIX = '/cgi-bin/tag/list';
    /**
     * 获取标签列表
     * @return bool|mixed
     * @throws \yii\web\HttpException
     */
    public function getTagList()
    {
        $result = $this->httpGet(self::WECHAT_TAG_LIST_GET_PREFIX, [
            'access_token' => $this->getAccessToken()
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result['taglist'] : false;
    }

    /**
     * 邀请成员关注
     */
    const WECHAT_USER_BATCH_INVITE_PREFIX = '/cgi-bin/batch/inviteuser';
    /**
     * 邀请成员关注
     * @param array $data
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function batchInviteUser(array $data)
    {
        $result = $this->httpRaw(self::WECHAT_TAG_USERS_DELETE_PREFIX, $data, [
            'access_token' => $this->getAccessToken()
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result['jobid'] : false;
    }

    /**
     * 增量更新成员
     */
    const WECHAT_USER_BATCH_SYNC_PREFIX = '/cgi-bin/batch/syncuser';
    /**
     * 增量更新成员
     * @param array $data
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function batchSyncUser(array $data)
    {
        $result = $this->httpRaw(self::WECHAT_USER_BATCH_SYNC_PREFIX, $data, [
            'access_token' => $this->getAccessToken()
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result['jobid'] : false;
    }

    /**
     * 全量覆盖成员
     */
    const WECHAT_USER_BATCH_REPLACE_PREFIX = '/cgi-bin/batch/replaceuser';
    /**
     * 全量覆盖成员
     * @param array $data
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function batchReplaceUser(array $data)
    {
        $result = $this->httpRaw(self::WECHAT_USER_BATCH_REPLACE_PREFIX, $data, [
            'access_token' => $this->getAccessToken(),
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result['jobid'] : false;
    }

    /**
     * 全量覆盖部门
     */
    const WECHAT_PARTY_BATCH_REPLACE_PREFIX = '/cgi-bin/batch/replaceparty';
    /**
     * 全量覆盖部门
     * @param array $data
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function batchReplaceParty(array $data)
    {
        $result = $this->httpRaw(self::WECHAT_PARTY_BATCH_REPLACE_PREFIX, $data, [
            'access_token' => $this->getAccessToken()
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result['jobid'] : false;
    }

    /**
     * 获取异步任务结果
     */
    const WECHAT_BATCH_RESULT_GET_PREFIX = '/cgi-bin/batch/getresult';
    /**
     * 获取异步任务结果
     * @param $jobId
     * @return bool|mixed
     * @throws \yii\web\HttpException
     */
    public function getBatchResult($jobId)
    {
        $result = $this->httpGet(self::WECHAT_BATCH_RESULT_GET_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'jobid' => $jobId
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result : false;
    }

    /* =================== 管理多媒体文件 =================== */

    /**
     * 上传媒体文件
     */
    const WECHAT_MEDIA_UPLOAD_PREFIX = '/cgi-bin/media/upload';
    /**
     * 上传媒体文件
     * @param $mediaPath
     * @param $type
     * @return bool|mixed
     * @throws \yii\web\HttpException
     */
    public function updateMedia($mediaPath, $type)
    {
        $result = $this->httpPost(self::WECHAT_MEDIA_UPLOAD_PREFIX, [
            'media' => $this->uploadFile($mediaPath)
        ], [
            'access_token' => $this->getAccessToken(),
            'type' => $type
        ]);
        return isset($result['media_id']) ? $result : false;
    }

    /**
     * 获取媒体文件
     */
    const WECHAT_MEDIA_GET_PREFIX = '/cgi-bin/media/get';
    /**
     * 获取媒体文件
     * @param $mediaId
     * @return bool|string
     * @throws \yii\web\HttpException
     */
    public function getMedia($mediaId)
    {
        $result = $this->httpGet(self::WECHAT_MEDIA_GET_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'media_id' => $mediaId
        ]);
        return !isset($result['errcode']) ? $result : false;
    }

    /* =================== 管理企业号应用 =================== */

    /**
     * 获取企业号应用
     */
    const WECHAT_AGENT_GET_PREFIX = '/cgi-bin/agent/get';
    /**
     * 获取企业号应用
     * @param $agentId
     * @return bool|mixed
     * @throws \yii\web\HttpException
     */
    public function getAgent($agentId)
    {
        $result = $this->httpGet(self::WECHAT_AGENT_GET_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'agent_id' => $agentId
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result : false;
    }

    /**
     * 设置企业号应用
     */
    const WECHAT_AGENT_SET_PREFIX = '/cgi-bin/agent/set';
    /**
     * 设置企业号应用
     * @param array $data
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function setAgent(array $data)
    {
        $result = $this->httpRaw(self::WECHAT_AGENT_SET_PREFIX, $data, [
            'access_token' => $this->getAccessToken()
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok';
    }

    /**
     * 获取应用概况列表
     */
    const WECHAT_AGENT_LIST_GET_PREFIX = '/cgi-bin/agent/list';
    /**
     * 获取应用概况列表
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function getAgentList()
    {
        $result = $this->httpGet(self::WECHAT_AGENT_SET_PREFIX, [
            'access_token' => $this->getAccessToken()
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result['agentlist'] : false;
    }

    /* =================== 发送消息 =================== */

    /**
     * 发送消息
     */
    const WECHAT_MESSAGE_SEND_PREFIX = '/cgi-bin/message/send';
    /**
     * 发送消息
     * @param array $data
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function sendMessage(array $data)
    {
        $result = $this->httpRaw(self::WECHAT_CUSTOM_MESSAGE_SEND_PREFIX, $data, [
            'access_token' => $this->getAccessToken()
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok' ? $result : false;
    }

    /* =================== 自定义菜单 =================== */

    /**
     * 创建应用菜单
     */
    const WECHAT_MENU_CREATE_PREFIX = '/cgi-bin/menu/create';
    /**
     * 创建应用菜单
     * @param $agentId
     * @param array $data
     * @return bool
     * @throws \yii\web\HttpException
     * example:
     *  array (
     *      'button' => array (
     *        0 => array (
     *          'name' => '扫码',
     *          'sub_button' => array (
     *              0 => array (
     *                'type' => 'scancode_waitmsg',
     *                'name' => '扫码带提示',
     *                'key' => 'rselfmenu_0_0',
     *              ),
     *              1 => array (
     *                'type' => 'scancode_push',
     *                'name' => '扫码推事件',
     *                'key' => 'rselfmenu_0_1',
     *              ),
     *          ),
     *        ),
     *        1 => array (
     *          'name' => '发图',
     *          'sub_button' => array (
     *              0 => array (
     *                'type' => 'pic_sysphoto',
     *                'name' => '系统拍照发图',
     *                'key' => 'rselfmenu_1_0',
     *              ),
     *              1 => array (
     *                'type' => 'pic_photo_or_album',
     *                'name' => '拍照或者相册发图',
     *                'key' => 'rselfmenu_1_1',
     *              )
     *          ),
     *        ),
     *        2 => array (
     *          'type' => 'location_select',
     *          'name' => '发送位置',
     *          'key' => 'rselfmenu_2_0'
     *        ),
     *      ),
     *  )
     * type可以选择为以下几种，会收到相应类型的事件推送。请注意，3到8的所有事件，仅支持微信iPhone5.4.1以上版本，
     * 和Android5.4以上版本的微信用户，旧版本微信用户点击后将没有回应，开发者也不能正常接收到事件推送。
     * 1、click：点击推事件
     * 2、view：跳转URL
     * 3、scancode_push：扫码推事件
     * 4、scancode_waitmsg：扫码推事件且弹出“消息接收中”提示框
     * 5、pic_sysphoto：弹出系统拍照发图
     * 6、pic_photo_or_album：弹出拍照或者相册发图
     * 7、pic_weixin：弹出微信相册发图器
     * 8、location_select：弹出地理位置选择器
     */
    public function createMenu($agentId, array $data)
    {
        $result = $this->httpRaw(self::WECHAT_MENU_CREATE_PREFIX, $data, [
            'access_token' => $this->getAccessToken(),
            'agentid' => $agentId
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok';
    }

    /**
     * 删除菜单
     */
    const WECHAT_MENU_DELETE_PREFIX = '/cgi-bin/menu/delete';
    /**
     * 删除菜单
     * @param $agentId
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function deleteMenu($agentId)
    {
        $result = $this->httpGet(self::WECHAT_MENU_DELETE_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'agentid' => $agentId
        ]);
        return isset($result['errmsg']) && $result['errmsg'] == 'ok';
    }

    /**
     * 获取菜单列表
     */
    const WECHAT_MENU_GET_PREFIX = '/cgi-bin/menu/get';
    /**
     * 获取菜单列表
     * @param $agentId
     * @return bool
     * @throws \yii\web\HttpException
     */
    public function getMenu($agentId)
    {
        $result = $this->httpGet(self::WECHAT_MENU_GET_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'agentid' => $agentId
        ]);
        return isset($result['menu']['button']) ? $result['menu']['button'] : false;
    }

    /* =================== OAuth2验证接口 =================== */

    /**
     * 企业获取code
     */
    const WECHAT_OAUTH2_AUTHORIZE_URL = 'https://open.weixin.qq.com/connect/oauth2/authorize';
    /**
     * 企业获取code:第
     * 通过此函数生成授权url
     * @param $redirectUrl 授权后重定向的回调链接地址，请使用urlencode对链接进行处理
     * @param string $state 重定向后会带上state参数，开发者可以填写a-zA-Z0-9的参数值
     * @param string $scope 应用授权作用域，snsapi_base （不弹出授权页面，直接跳转，只能获取用户openid），
     * snsapi_userinfo （弹出授权页面，可通过openid拿到昵称、性别、所在地。并且，即使在未关注的情况下，只要用户授权，也能获取其信息）
     * @return string
     */
    public function getOauth2AuthorizeUrl($redirectUrl, $state = 'authorize', $scope = 'snsapi_base')
    {
        return $this->httpBuildQuery(self::WECHAT_OAUTH2_AUTHORIZE_URL, [
            'appid' => $this->corpId,
            'redirect_uri' => $redirectUrl,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
        ]) . '#wechat_redirect';
    }

    /**
     * 根据code获取成员信息
     */
    const WECHAT_USER_IFNO_GET_PREFIX = '/cgi-bin/user/getuserinfo';
    /**
     * 根据code获取成员信息
     * @param $agentId
     * @param $code
     * @return bool|mixed
     * @throws \yii\web\HttpException
     */
    public function getUserInfo($agentId, $code)
    {
        $result = $this->httpGet(self::WECHAT_USER_IFNO_GET_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'code' => $code,
            'agentid' => $agentId
        ]);
        return !isset($result['errcode']) ? $result : false;
    }

    /* =================== 微信JS接口 =================== */

    /**
     * js api ticket 获取
     */
    const WECHAT_JS_API_TICKET_PREFIX = '/cgi-bin/get_jsapi_ticket';
    /**
     * 请求服务器jsapi_ticket
     * @return array
     */
    protected function requestJsApiTicket()
    {
        return $this->httpGet(self::WECHAT_JS_API_TICKET_PREFIX, [
            'access_token' => $this->getAccessToken(),
        ]);
    }

    /**
     * 生成js 必需的config
     * 只需在视图文件输出JS代码:
     *  wx.config(<?= json_encode($wehcat->jsApiConfig()) ?>); // 默认全权限
     *  wx.config(<?= json_encode($wehcat->jsApiConfig([ // 只允许使用分享到朋友圈功能
     *      'jsApiList' => [
     *          'onMenuShareTimeline'
     *      ]
     *  ])) ?>);
     * @param array $config
     * @return array
     * @throws HttpException
     */
    public function jsApiConfig(array $config = [])
    {
        $data = [
            'jsapi_ticket' => $this->getJsApiTicket(),
            'noncestr' => Yii::$app->security->generateRandomString(16),
            'timestamp' => $_SERVER['REQUEST_TIME'],
            'url' => explode('#', Yii::$app->request->getAbsoluteUrl())[0]
        ];
        return array_merge([
            'debug' => YII_DEBUG,
            'appId' => $this->corpId,
            'timestamp' => $data['timestamp'],
            'nonceStr' => $data['noncestr'],
            'signature' => sha1(urldecode(http_build_query($data))),
            'jsApiList' => [
                'onMenuShareTimeline',
                'onMenuShareAppMessage',
                'onMenuShareQQ',
                'onMenuShareWeibo',
                'startRecord',
                'stopRecord',
                'onVoiceRecordEnd',
                'playVoice',
                'pauseVoice',
                'stopVoice',
                'onVoicePlayEnd',
                'uploadVoice',
                'downloadVoice',
                'chooseImage',
                'previewImage',
                'uploadImage',
                'downloadImage',
                'translateVoice',
                'getNetworkType',
                'openLocation',
                'getLocation',
                'hideOptionMenu',
                'showOptionMenu',
                'hideMenuItems',
                'showMenuItems',
                'hideAllNonBaseMenuItem',
                'showAllNonBaseMenuItem',
                'closeWindow',
                'scanQRCode'
            ]
        ], $config);
    }

    protected function createMessageCrypt() {
    }

    protected function getCacheKey($name) {
    }

    public function parseHttpRequest(callable $callable, $url, $postOptions = null) {
        $result = call_user_func_array($callable, [$url, $postOptions]);
        if (isset($result['errcode']) && $result['errcode']) {
            $this->lastError = $result;
            \Yii::warning([
                'url' => $url,
                'result' => $result,
                'postOptions' => $postOptions
            ], __METHOD__);
            switch ($result ['errcode']) {
                case 40001: //access_token 失效,强制更新access_token, 并更新地址重新执行请求
                    if ($force) {
                        $url = preg_replace_callback("/access_token=([^&]*)/i", function(){
                            return 'access_token=' . $this->getAccessToken(true);
                        }, $url);
                        $result = $this->parseHttpRequest($callable, $url, $postOptions, false); // 仅重新获取一次,否则容易死循环
                    }
                    break;
            }
        }
        return $result;
    }


    /* =================== 企业号登陆授权 =================== */
    /**
     * 应用提供商登陆授权
     * @param string $callback 回调URI
     * @param string $state 重定向后会带上state参数，企业可以填写a-zA-Z0-9的参数值
     * @return string
     */
    const LOGIN_GRANT_PREFIX = 'https://qy.weixin.qq.com/cgi-bin';
    const LOGIN_GRANT_URL = '/loginpage?';

    public function getLoginGrantUrl($callback,$state='STATE'){
        return self::LOGIN_GRANT_PREFIX.self::LOGIN_GRANT_URL.'corp_id='.$this->corpId.'&redirect_uri='.urlencode($callback).'&state='.$state.'#wechat_redirect';
    }

    /**
     * 获取应用提供商凭证
     * @param $agentId
     * @param $code
     * @return bool|mixed
     * @throws \yii\web\HttpException
     */
    const WECHAT_PROVIDER_GET_PREFIX = '/cgi-bin/user/get_provider_token';

    public function getProvider($corpid, $corpsecret)
    {
        $result = $this->httpPost(self::WECHAT_PROVIDER_GET_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'corpid' => $corpid,
            'provider_secret' => $corpsecret
        ]);
        return !isset($result['provider_access_token']) ? $result['provider_access_token'] : false;
    }

    /**
     * userid转换成openid接口
     * @param $userid
     * @param $agentid
     * @return bool|mixed
     * @throws \yii\web\HttpException
     */
    const WECHAT_USERID_TO_OPENID_PREFIX = '/cgi-bin/user/convert_to_openid';

    public function convertUseridToOpenid($userid, $agentid)
    {
        $result = $this->httpPost(self::WECHAT_USERID_TO_OPENID_PREFIX, [
            'access_token' => $this->getAccessToken(),
            'userid' => $userid,
            'agentid' => $agentid
        ]);
        return ($result['errcode']==0) ? $result['openid'] : false;
    }

}

/**
 * error code
 * 仅用作类内部使用，不用于官方API接口的errCode码
 */
class ErrorCode
{
    public static $OK = 0;
    public static $ValidateSignatureError = 40001;
    public static $ParseXmlError = 40002;
    public static $ComputeSignatureError = 40003;
    public static $IllegalAesKey = 40004;
    public static $ValidateAppidError = 40005;
    public static $EncryptAESError = 40006;
    public static $DecryptAESError = 40007;
    public static $IllegalBuffer = 40008;
    public static $EncodeBase64Error = 40009;
    public static $DecodeBase64Error = 40010;
    public static $GenReturnXmlError = 40011;
    public static $errCode=array(
            '0'=>'无问题',
            '40001'=>'签名验证错误',
            '40002'=>'xml解析失败',
            '40003'=>'sha加密生成签名失败',
            '40004'=>'encodingAesKey 非法',
            '40005'=>'appid 校验错误',
            '40006'=>'aes 加密失败',
            '40007'=>'aes 解密失败',
            '40008'=>'解密后得到的buffer非法',
            '40009'=>'base64加密失败',
            '40010'=>'base64解密失败',
            '40011'=>'生成xml失败',
    );
    public static function getErrText($err) {
        if (isset(self::$errCode[$err])) {
            return self::$errCode[$err];
        }else {
            return false;
        };
    }
}