<?php
/**
 * Created by PhpStorm.
 * User: qiao
 * Date: 2016/7/29
 * Time: 18:55
 */
namespace UE;
include "Uploader.class.php";
use Qiniu\Auth;
use Qiniu\Storage\BucketManager;
define('ACCESS_KEY','Access_Key');
define('SECRET_KEY','Secret_key');
define('BUCKET','Bucket_Name');
date_default_timezone_set("Asia/chongqing");
error_reporting(E_ERROR);
header("Content-Type: text/html; charset=utf-8");

$CONFIG = json_decode(preg_replace("/\/\*[\s\S]+?\*\//", "", file_get_contents("config.json")), true);
$action = $_GET['action'];
$base64 = 'upload';
$config = [
    "pathFormat" => $CONFIG['filePathFormat'],
    "maxSize" => $CONFIG['fileMaxSize'],
    "allowFiles" => $CONFIG['fileAllowFiles']
];
$fieldName = $CONFIG['fileFieldName'];
$auth = new Auth(ACCESS_KEY,SECRET_KEY);
$bmgr = new BucketManager($auth);

function getFiles(BucketManager $bmgr,$prefix,$start = '',$size = 20){
    $data = [];
    list($iterms, $marker, $err) = $bmgr->listFiles(BUCKET,$prefix,$start,$size);
    if($err != null){
        $data = [
            "state" => $err,
            "list" => [],
            "start" => $marker,
            "total" => 0
        ];
    }else{
        foreach ($iterms as $k=>$v){
            $iterms[$k]['url']= $v['key'];
        }
        $data = [
            "state" => "SUCCESS",
            "list" => $iterms,
            "start" => $marker,
            "total" => count($iterms)
        ];
    }
    return $data;
}
switch ($action) {
    case 'config':
        $result =  json_encode($CONFIG);
        break;

    /* 上传图片 */
    case 'uploadimage':
        $config = [
            'pathFormat' => $CONFIG['imagePathFormat'],
            'maxSize' => $CONFIG['imageMaxSize'],
            'allowFiles' => $CONFIG['imageAllowFiles']
        ];
        $fieldName = $CONFIG['imageFieldName'];
        $up = new Uploader($fieldName,$config,$base64);
        $result = json_encode($up->getFileInfo());
        break;
        /* 上传涂鸦 */
    case 'uploadscrawl':
        $config = [
            "pathFormat" => $CONFIG['scrawlPathFormat'],
            "maxSize" => $CONFIG['scrawlMaxSize'],
            "allowFiles" => $CONFIG['scrawlAllowFiles'],
            "oriName" => "scrawl.png"
        ];
        $fieldName = $CONFIG['scrawlFieldName'];
        $base64 = "base64";
        $up = new Uploader($fieldName,$config,$base64);
        $result = json_encode($up->getFileInfo());
        break;
        /* 上传视频 */
    case 'uploadvideo':
        $config = array(
            "pathFormat" => $CONFIG['videoPathFormat'],
            "maxSize" => $CONFIG['videoMaxSize'],
            "allowFiles" => $CONFIG['videoAllowFiles']
        );
        $fieldName = $CONFIG['videoFieldName'];
        $up = new Uploader($fieldName,$config,$base64);
        $result = json_encode($up->getFileInfo());
        break;
        /* 上传文件 */
    case 'uploadfile':
        $up = new Uploader($fieldName,$config,$base64);
        $result = json_encode($up->getFileInfo());
        break;

    /* 列出图片 */
    case 'listimage':
        $prefix = 'ueditor_image';
        $size = isset($_GET['size']) ? htmlspecialchars($_GET['size']) : 20;
        $start = isset($_GET['start']) ? htmlspecialchars($_GET['start']) : 0;
        $data = getFiles($bmgr,$prefix,$start,$size);
        $result = json_encode($data);
        break;
    /* 列出文件 */
    case 'listfile':
        $prefix = 'ueditor_file';
        $size = isset($_GET['size']) ? htmlspecialchars($_GET['size']) : 20;
        $start = isset($_GET['start']) ? htmlspecialchars($_GET['start']) : 0;
        $data = getFiles($bmgr,$prefix,$start,$size);
        $result = json_encode($data);
        break;

    /* 抓取远程文件 */
    case 'catchimage':
        set_time_limit(0);
        /* 上传配置 */
        $config = [
            "pathFormat" => $CONFIG['catcherPathFormat'],
            "maxSize" => $CONFIG['catcherMaxSize'],
            "allowFiles" => $CONFIG['catcherAllowFiles'],
            "oriName" => "remote.png"
        ];
        $fieldName = $CONFIG['catcherFieldName'];
        /* 抓取远程图片 */
        $list = [];
        if (isset($_POST[$fieldName])) {
            $source = $_POST[$fieldName];
        } else {
            $source = $_GET[$fieldName];
        }
        foreach ($source as $imgUrl) {
            $item = new Uploader($imgUrl, $config, "remote");
            $info = $item->getFileInfo();
            array_push($list, array(
                "state" => $info["state"],
                "url" => $info["url"],
                "size" => $info["size"],
                "title" => htmlspecialchars($info["title"]),
                "original" => htmlspecialchars($info["original"]),
                "source" => htmlspecialchars($imgUrl)
            ));
        }
        $result = json_encode(['state'=>empty($list) ? 'ERROR':'SUCCESS','list' => $list]);
        break;

    default:
        $result = json_encode(array(
            'state'=> '请求地址出错'
        ));
        break;
}

/* 输出结果 */
if (isset($_GET["callback"])) {
    if (preg_match("/^[\w_]+$/", $_GET["callback"])) {
        echo htmlspecialchars($_GET["callback"]) . '(' . $result . ')';
    } else {
        echo json_encode(array(
            'state'=> 'callback参数不合法'
        ));
    }
} else {
    echo $result;
}