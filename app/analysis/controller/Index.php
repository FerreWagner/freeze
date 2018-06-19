<?php
namespace app\analysis\controller;

use app\analysis\Common;
use think\Request;

class Index extends Common
{
    //function: 1、提取生成词频前n个(var:词汇、数量、词重);
    public function index()
    {
        $this->dlfileftxt('', '你好世界');die; //https://blog.csdn.net/oQiWei1/article/details/62432315
        return $this->view->fetch('index');
        
        $this->pic();
        
        //表单 验证
        $url  = 'http://heater.fsociaty.com';
        $time = is_numeric(20) ? 20 : 40;
        if (!filter_var($url, FILTER_VALIDATE_URL)) $this->error('不是标准的地址');
        
        
        
        //分析
        $devid   = $this->analysisWeb($url, $time);
        
        halt($devid);
        return $this->view->fetch('index');
    }
    
    public function programme2(Request $request)
    {
        if ($request->isPost()){
            //表单 验证
            $form = $request->param();
            if (empty($form['url']) || empty($form['time'])) $this->error('表单数据未填写完整,请重新填写');
        
            $time = is_numeric($form['time']) ? $form['time'] : 40;   //初始化分词数
            $url  = $form['url'];
            if (!filter_var($url, FILTER_VALIDATE_URL)) $this->error('不是标准的地址');
            
            //分析
            $devid   = $this->analysisWeb($url, $time);
            $string  = $times = $weight = [];
            foreach ($devid as $_k => $_v){
                $string[] = $_v['word'];
                $times[]  = $_v['times'];
                $weight[] = $_v['weight'];
            }
            //做devid数据为空判断 TODO
            halt($weight);
//             return $this->view->fetch('program2-end', ['string' => $string]);
        }
        
        return $this->view->fetch('program2');
    }
    
    public function dlfileftxt($data = array(),$filename = "unknown") {
        header("Content-type:application/octet-stream");
        header("Accept-Ranges:bytes");
        header("Content-Disposition:attachment;filename=$filename.txt");
        header("Expires:0");
        header("Cache-Control:must-revalidate,post-check=0,pre-check=0 ");
        header("Pragma:public");
        if (!empty($data)){
            foreach($data as $key=>$val){
                foreach ($val as $ck => $cv) {
                    $data[$key][$ck]=iconv("UTF-8", "GB2312", $cv);
                }
                $data[$key]=implode(" ", $data[$key]);
            }
            echo implode("\n",$data);
        }
        exit();
    }
    
    /**
     * 方案3
     * @param Request $request
     * @return string
     */
    public function programme3(Request $request)
    {
        
        if ($request->isPost()){
            //表单 验证
            $form = $request->param();
            if (empty($form['url']) || empty($form['time'])) $this->error('表单数据未填写完整,请重新填写');
            
            $time = is_numeric($form['time']) ? $form['time'] : 40;   //初始化分词数
            $url  = $form['url'];
            if (!filter_var($url, FILTER_VALIDATE_URL)) $this->error('不是标准的地址');
            
            //分析
            $devid   = $this->analysisWeb($url, $time);
            $count   = count($devid);
            $string  = '';
            foreach ($devid as $_k => $_v){
                for ($i = 0; $i < $count; $i ++){
                    $string .= $_v['word'].' ';
                }
                $count --;
            }
            if (empty($devid)) $this->error('该站点存在错误');
            return $this->view->fetch('program3-end', ['string' => $string]);
        }
        
        return $this->view->fetch('program3');
    }
    
    //function: 1、提取生成词频前n个(var:词汇),使用GD库生成标签云;TIPS:图片大小；图片背景；文字大小；文字颜色(不统一)；文字字体；文字间距
    public function pic()
    {
        //表单数据提取
        $img_format = ['jpg', 'jpeg', 'png', 'gif'];
        $ext        = 'jpg';
        $font_size  = 50;    //最大的font size,最小的为18
        $font_sty   = "C:\Users\Administrator\Downloads\Austie Bost Versailles.ttf";
        
        //表单验证 TODO
        if (!in_array($ext, $img_format)) $this->error('抱歉，不支持的图片格式');
        
        //颜色处理/文字间距 TODO
        
        // 定义输出为图像类型
        header("content-type:image/$ext");
        
        // 创建画布,默认600x400
        $im = imagecreate(600, 400);
        // 背景,默认白色
        imagecolorallocate($im, 255, 255, 255);
        
        // 文本颜色
        $text_color = imagecolorallocate($im, 233, 14, 91);
        $motto = "asd";
        //imagestring 默认英文编码，只支持UTF-8
        //imagestring($im, 2, 0, 0, $motto, $text_color);
        
        //当代码文件为:
        //ANSI编码，需要转换
        //UTF-8编码，不需要转换
        //$motto = iconv("gb2312", "utf-8", $motto);
        //image resource,float size,float angle,int x,int y,int color,string fontfile,string text
        imageTTFText($im, 18, 0, 80, 100, $text_color, $font_sty, $motto);
        imageTTFText($im, 40, 0, 10, 140, $text_color, $font_sty, 'I LOVE ALEXA');
        
        switch ($ext)
        {
            case 'jpg' || 'jpeg':
                imagejpeg($im);
            case 'png':
                imagepng($im);
            case 'gif':
                imagegif($im);
            default:
                imagejpeg($im);
        }
        
        imagedestroy($im);die;
    }
    

}
