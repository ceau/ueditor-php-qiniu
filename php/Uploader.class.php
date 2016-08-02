<?php
/**
 * Created by PhpStorm.
 * User: qiao
 * Date: 2016/7/29
 * Time: 14:58
 */

namespace UE;
require '../vendor/autoload.php';
use Qiniu\Auth;
use Qiniu\Storage\UploadManager;
use Qiniu\Storage\BucketManager;

class Uploader
{
    private $fileField; //文件域名
    private $file; //文件上传对象
    private $base64; //文件上传对象
    private $config; //配置信息
    private $oriName; //原始文件名
    private $fileName; //新文件名
    private $fullName; //完整文件名,即从当前配置目录开始的URL
    private $filePath; //完整文件名,即从当前配置目录开始的URL
    private $fileSize; //文件大小
    private $fileType; //文件类型
    private $stateInfo; //上传状态信息,
    private $stateMap = [//上传状态映射表，国际化用户需考虑此处数据的国际化
        "SUCCESS", //上传成功标记，在UEditor中内不可改变，否则flash判断会出错
        "文件大小超出 upload_max_filesize 限制",
        "文件大小超出 MAX_FILE_SIZE 限制",
        "文件未被完整上传",
        "没有文件被上传",
        "上传文件为空",
        "ERROR_TMP_FILE" => "临时文件错误",
        "ERROR_TMP_FILE_NOT_FOUND" => "找不到临时文件",
        "ERROR_SIZE_EXCEED" => "文件大小超出网站限制",
        "ERROR_CREATE_DIR" => "目录创建失败",
        "ERROR_DIR_NOT_WRITEABLE" => "目录没有写权限",
        "ERROR_FILE_MOVE" => "文件保存时出错",
        "ERROR_FILE_NOT_FOUND" => "找不到上传文件",
        "ERROR_WRITE_CONTENT" => "写入文件内容错误",
        "ERROR_UNKNOWN" => "未知错误",
        "ERROR_DEAD_LINK" => "链接不可用",
        "ERROR_HTTP_LINK" => "链接不是http链接",
        "ERROR_HTTP_CONTENTTYPE" => "链接contentType不正确",
        "INVALID_URL" => "非法 URL",
        "INVALID_IP" => "非法 IP",
        400=>"请求报文格式错误，报文构造不正确或者没有完整发送",
        401=>"上传凭证无效",
        413=>"上传内容长度大于 fsizeLimit中指定的长度限制",
        579=>"回调业务服务器失败",
        599=>'服务端操作失败',
        614=>"目标资源已存在",
        "ERROR_TYPE_NOT_ALLOWED" => "文件类型不允许"
    ];
    private $accessKey = 'Access_Key';
    private $secretKey = 'Secret_Key';
    private $bucket = 'Bucket_Name'; // 要上传的空间
    private $auth;
    private $token;
    private $uploadMgr;
    private $bmgr;

    public function __construct($fileField, $config, $type = 'upload')
    {
        $this->fileField = $fileField;
        $this->config = $config;
        $this->type = $type;

        if(empty($this->auth)){
            $this->auth = new Auth($this->accessKey, $this->secretKey);
        }
        if(empty($this->token)){
            $this->token = $this->auth->uploadToken($this->bucket);
        }
        if(empty($this->uploadMgr)){
            $this->uploadMgr = new UploadManager();
        }

        if(empty($this->bmgr)){
            $this->bmgr = new BucketManager($this->auth);
        }

        if ($type == "remote") {
            $this->saveRemote();
        } else if($type == "base64") {
            $this->upBase64();
        } else {
            $this->upFile();
        }

        $this->stateMap['ERROR_TYPE_NOT_ALLOWED'] = iconv('unicode', 'utf-8', $this->stateMap['ERROR_TYPE_NOT_ALLOWED']);

    }

    /**
     * 上传文件的主处理方法
     * @return mixed
     */
    private function upFile()
    {
        $file = $this->file = $_FILES[$this->fileField];
        if(!$file){
            $this->stateInfo = $this->getStateInfo('ERROR_FILE_NOT_FOUND');
            return ;
        }

        if($this->file['error']){
            $this->stateInfo = $this->getStateInfo($file['error']);
            return;
        }else if (!file_exists($file['tmp_name'])) {
            $this->stateInfo = $this->getStateInfo("ERROR_TMP_FILE_NOT_FOUND");
            return;
        } else if (!is_uploaded_file($file['tmp_name'])) {
            $this->stateInfo = $this->getStateInfo("ERROR_TMPFILE");
            return;
        }

        $this->oriName = $file['name'];
        $this->fileSize = $file['size'];
        $this->fileType = $this->getFileExt();
        $this->fullName = $this->getFullName();
        $this->fileName = $this->fullName;

        //检查文件大小是否超出限制
        if (!$this->checkSize()) {
            $this->stateInfo = $this->getStateInfo("ERROR_SIZE_EXCEED");
            return;
        }

        //检查是否不允许的文件格式
        if (!$this->checkType()) {
            $this->stateInfo = $this->getStateInfo("ERROR_TYPE_NOT_ALLOWED");
            return;
        }

        // 要上传文件的本地路径
        $filePath = $file['tmp_name'];

        // 上传到七牛后保存的文件名
        $key = $this->fullName;

        // 调用 UploadManager 的 putFile 方法进行文件的上传
        list($ret, $err) = $this->uploadMgr->putFile($this->token, $key, $filePath);
        if ($err !== null) {
            $this->stateInfo = $err;
        } else {
            $this->stateInfo = $this->stateMap[0];
        }

    }

    private function upBase64()
    {
        $base64Data = $_POST[$this->fileField];

        $info = $this->getInfo($base64Data);
        if(!$info){
            return ;
        }

        $this->fileSize = $info[1];
        $this->fileType = $info[0];
        $this->oriName = 'base64'.$this->fileType;

        $this->fileName = $this->fullName = ($this->getFileName()) . $this->fileType ;


        //检查文件大小是否超出限制
        if (!$this->checkSize()) {
            $this->stateInfo = $this->getStateInfo("ERROR_SIZE_EXCEED");
            return;
        }

        // 要上传文件的本地路径
        $filePath = $base64Data;

        // 上传到七牛后保存的文件名
        $key = $this->fullName;

        // 调用 UploadManager 的 putFile 方法进行文件的上传
        list($ret, $err) = $this->uploadMgr->putFile($this->token, $key, $filePath);
        if ($err !== null) {
            $this->stateInfo = $err;
        } else {
            $this->stateInfo = $this->stateMap[0];
        }

    }
    /**
     * 上传错误检查
     * @param $errCode
     * @return string
     */
    private function getStateInfo($errCode)
    {
        return !$this->stateMap[$errCode] ? $this->stateMap["ERROR_UNKNOWN"] : $this->stateMap[$errCode];
    }

    private function getFileExt()
    {
        return '.' . pathinfo($this->oriName,PATHINFO_EXTENSION);
    }
    /**
     * 重命名文件
     * @return string
     */
    private function getFullName()
    {
        $format = $this->getFileName();
        $ext = $this->getFileExt();
        return $format . $ext;
    }
    private function getFileName(){
        //替换日期事件
        $t = time();
        $d = explode('-', date("Y-y-m-d-H-i-s"));
        $format = $this->config["pathFormat"];

        $search_array = ["{yyyy}","{yy}","{mm}","{dd}","{hh}","{ii}","{ss}","{time}"];
        $replace_array = array_merge($d,[$t]);

        $format = str_replace($search_array,$replace_array,$format);

        //过滤文件名的非法字符,并替换文件名
        $oriName = substr($this->oriName, 0, strrpos($this->oriName, '.'));
        $oriName = preg_replace("/[\|\?\"\<\>\/\*\\\\]+/", '', $oriName);
        $format = str_replace("{filename}", $oriName, $format);

        //替换随机字符串
        $randNum = rand(1, 10000000000) . rand(1, 10000000000);
        if (preg_match("/\{rand\:([\d]*)\}/i", $format, $matches)) {
            $format = preg_replace("/\{rand\:[\d]*\}/i", substr($randNum, 0, $matches[1]), $format);
        }
        return $format;
    }
    /**
     * 文件类型检测
     * @return bool
     */
    private function checkType()
    {
        return in_array($this->getFileExt(), $this->config["allowFiles"]);
    }

    /**
     * 文件大小检测
     * @return bool
     */
    private function  checkSize()
    {
        return $this->fileSize <= ($this->config["maxSize"]);
    }

    private function upFileToQiniu()
    {

    }

    private function getInfo($base64Data){
        //data:image/png;base64,iVBORw0K
        $start_index = 4; //第一个冒号
        $end_index = strrpos($base64Data,',');
        if($end_index <= $start_index){
            return false;
        }

        $str = substr($base64Data,$start_index+1,$end_index-$start_index-1);
        if(strrpos($str,'base64') !== false && strrpos($str,'image') !== false){
            return false;
        }

        $ext = '.'.str_replace('e','',substr($str,6,strlen($str)-13));
        $img = substr($base64Data,$end_index+1);
        $size = strlen(base64_decode($img));

        return [$ext,$size];
    }

    /**
     * 拉取远程图片
     * @return mixed
     */
    private function saveRemote()
    {
        $imgUrl = htmlspecialchars($this->fileField);
        $imgUrl = str_replace("&amp;", "&", $imgUrl);

        //http开头验证
        if (strpos($imgUrl, "http") !== 0) {
            $this->stateInfo = $this->getStateInfo("ERROR_HTTP_LINK");
            return;
        }

        preg_match('/(^https*:\/\/[^:\/]+)/', $imgUrl, $matches);
        $host_with_protocol = count($matches) > 1 ? $matches[1] : '';

        // 判断是否是合法 url
        if (!filter_var($host_with_protocol, FILTER_VALIDATE_URL)) {
            $this->stateInfo = $this->getStateInfo("INVALID_URL");
            return;
        }

        preg_match('/^https*:\/\/(.+)/', $host_with_protocol, $matches);
        $host_without_protocol = count($matches) > 1 ? $matches[1] : '';

        // 此时提取出来的可能是 ip 也有可能是域名，先获取 ip
        $ip = gethostbyname($host_without_protocol);
        // 判断是否是私有 ip
        if(!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
            $this->stateInfo = $this->getStateInfo("INVALID_IP");
            return;
        }

        //获取请求头并检测死链
        $heads = get_headers($imgUrl, 1);
        if (!(stristr($heads[0], "200") && stristr($heads[0], "OK"))) {
            $this->stateInfo = $this->getStateInfo("ERROR_DEAD_LINK");
            return;
        }
        //格式验证(扩展名验证和Content-Type验证)
        $fileType = strtolower(strrchr($imgUrl, '.'));
        if (!in_array($fileType, $this->config['allowFiles']) || !isset($heads['Content-Type']) || !stristr($heads['Content-Type'], "image")) {
            $this->stateInfo = $this->getStateInfo("ERROR_HTTP_CONTENTTYPE");
            return;
        }

        //打开输出缓冲区并获取远程图片
        ob_start();
        $context = stream_context_create(
            array('http' => array(
                'follow_location' => false // don't follow redirects
            ))
        );
        readfile($imgUrl, false, $context);
        $img = ob_get_contents();
        ob_end_clean();
        preg_match("/[\/]([^\/]*)[\.]?[^\.\/]*$/", $imgUrl, $m);

        $this->oriName = $m ? $m[1]:"";
        $this->fileSize = strlen($img);
        $this->fileType = $this->getFileExt();
        $this->fileName = $this->fullName = $this->getFullName();

        //检查文件大小是否超出限制
        if (!$this->checkSize()) {
            $this->stateInfo = $this->getStateInfo("ERROR_SIZE_EXCEED");
            return;
        }

        list($ret, $err) = $this->bmgr->fetch($imgUrl, $this->bucket, $this->fullName);

        if ($err !== null) {
            $this->stateInfo = $err;
        } else {
            $this->stateInfo = $this->stateMap[0];
        }
    }

    /**
     * 获取当前上传成功文件的各项信息
     * @return array
     */
    public function getFileInfo()
    {
        return [
            "state" => $this->stateInfo,
            "url" => $this->fullName,
            "title" => $this->fileName,
            "original" => $this->oriName,
            "type" => $this->fileType,
            "size" => $this->fileSize
        ];
    }
}