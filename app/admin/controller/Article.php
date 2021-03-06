<?php

namespace app\admin\controller;

use Qiniu\Auth;
use Qiniu\Storage\UploadManager;
use QL\QueryList;
use think\Request;
use app\admin\common\Base;
use think\Loader;
use app\admin\model\Article as ArticleModel;
use think\Validate;
use function GuzzleHttp\Promise\all;
use function QL\html;

class Article extends Base
{

    /**
     * 前置操作
     */
//     protected $beforeActionList  = [
//         'mailServe' => ['only' => 'delete'],    //前置操作的方法请勿在前添加空格
//     ];


    /**
     * 显示资源列表
     *
     * @return \think\Response
     */
    public function index(Request $request)
    {
        $article = db('article')->field('a.*,b.catename')->alias('a')->join('claire_category b','a.cate=b.id')->order('a.id desc')->paginate(config('conf.page'));
        $count   = db('article')->count();
        //search function
        if ($request->isPost()){
            $search  = $request->param();

            if (empty($search['start']) || empty($search['end'])){
                $article = db('article')->field('a.*,b.catename')->alias('a')->join('claire_category b','a.cate=b.id')->order('a.id desc')
                    ->where('title', 'like', '%'.$search['title'].'%')->paginate(config('conf.page'));

            }else {
                $article = db('article')->field('a.*,b.catename')->alias('a')->join('claire_category b','a.cate=b.id')->order('a.id desc')
                    ->where('time', 'between', [strtotime($search['start']), strtotime($search['end'])])
                    ->where('title', 'like', '%'.$search['title'].'%')->paginate(config('conf.page'));

            }
        }

        //list
        $this->view->assign([
            'article' => $article,
            'count'   => $count,
        ]);
        return $this->view->fetch('article-list');
    }


    /**
     * 保存新建的资源
     *
     * @param  \think\Request  $request
     * @return \think\Response
     */
    public function add(Request $request)
    {
        //add
        if ($request->isPost()){

            $token      = Validate::token('__token__','',['__token__'=>input('param.__token__')]);    //CSRF validate
            if (!$token) $this->error('CSRF ATTACK.');

            $data = input('post.');
            $data['time'] = time();    //写入时间戳
            $validate = Loader::validate('Article');
            if(!$validate->scene('add')->check($data)){
                $this->error($validate->getError());
            }
            $article = new ArticleModel();
            if($article->allowField(true)->save($data)){
                $this->redirect('admin/article/index');
            }else{
                $this->error('添加失败');
            }
            return;
        }
        //page
        $cate = db('category')->field(['id', 'catename'])->order('sort', 'asc')->select();
        $this->view->assign('cate', $cate);
        return $this->view->fetch('article-add');
    }

    /**
     * 显示编辑资源表单页.
     *
     * @param  int  $id
     * @return \think\Response
     */
    public function edit(Request $request, $id)
    {
        if ($request->isPost()){
            $data = $request->param();
            $validate = Loader::validate('Article');
            if(!$validate->scene('edit')->check($data)){
                $this->error($validate->getError());
            }
            $article = new ArticleModel;
            $save=$article->update($data);
            if($save){
                $this->success('修改文章成功！',url('admin/article/index'));
            }else{
                $this->error('修改文章失败！');
            }
            return;
        }
        //cate data && article data
        $cate    = db('category')->field(['id', 'catename'])->order('sort', 'asc')->select();
        $article = db('article')->find($id);

        $type    = ArticleModel::getSystem()['type'];   //缩略图type

        //当然这里只是做简略处理，为了不让article表性能变低，我们将type字段分离到system表，如果在三方服务器和本地均存有图片，那么我们可以通过判断路径名来确定是否添加http://这样的完整路径
        $article['thumb'] = ($type == 0 || empty($article['content'])) ? $article['thumb'] : 'http://'.$article['thumb'];
        $this->assign(['cate' => $cate, 'article' => $article]);
        return $this->view->fetch('article-edit');
    }


    /**
     * 删除指定资源
     *
     * @param  int  $id
     * @return \think\Response
     */
    public function delete($id)
    {
        if(ArticleModel::destroy($id)){
            $this->success('删除文章成功！',url('admin/article/index'));
        }else{
            $this->error('删除文章失败！');
        }
    }


    /**
     *
     * doCrawl表单处理
     * @return string
     */
    public function doCrawl(Request $request)
    {
        if ($request->isPost()){
            $form = $request->param();
            $baseurl = parse_url($form['url'])['scheme'].'://'.parse_url($form['url'])['host']; //构建完整URL

            //获取html,已用CURL方法替换
            //$html   = file_get_contents($form['url']);
            $html   = $this->fetch_url_page_contents($form['url']);

            //解析首页图
            $img_rule   = ['img' => ['img', 'src'],];
            $url_rule   = ['url' => ['a', 'href'],];

            //解析html层数
            $total_img  = $total_url = $now_url = [];
            $deep       = 1;

            //迭代url和get图片流
            while ($deep <= $form['deep']){
                //第一次循环 re为//返回url并继续处理
                if ($deep == 1){
                    $result = $this->getPageData($form['url'], $html, $img_rule, $url_rule, $baseurl);
                    $total_img = array_merge($result[0], $total_img);
                    $total_url = $result[1];
                }else {
                    if (!empty($now_url)){
                        $total_url = $now_url;
                        $now_url   = [];
                    }
                    foreach ($total_url as $__url){
                        $_html     = $this->fetch_url_page_contents($__url);
                        $result    = $this->getPageData($__url, $_html, $img_rule, $url_rule, $baseurl);
                        $total_img = array_merge($result[0], $total_img);
                        $now_url   = array_merge($result[1]);
                        $now_url   = array_unique($now_url);
                    }
                }
                $deep ++;
            }
            //去重
            $total_img = array_unique($total_img);
            //库数据去重
            $in_img     = db('article')->where('pic', 'in', $total_img)->column('pic');
            $filter_img = array_diff($total_img, $in_img);
            //最大插入限制
            //if (count($filter_img) > $form['number']) $filter_img = array_slice($filter_img, $form['number']);

            //构造数据
            $sql_data = [];
            foreach ($filter_img as $_value){
                $see = random_int(60, 2000);
                $sql_data[]  = [
                    'cate'   => $form['cate'],
                    'author' => 'internet',
                    'order'  => $form['order'],
                    'see'    => $see,
                    'pic'    => $_value,
                    'time'   => time(),
                ];

            }

            if (is_numeric(db('article')->insertAll($sql_data))){
                $this->success('爬取成功');
            }else {
                $this->error('爬取失败');
            }
            //采集某页面所有的图片
//             $_src = QueryList::get($form['url'])->find('img')->attrs('src');
//             //打印结果
//             $_src->all();
        }

        $cate = db('category')->field(['id', 'catename'])->order('sort', 'asc')->select();
        return $this->view->fetch('article-do', ['cate' => $cate]);
    }

    /**
     * 单页爬取
     * @return string
     */
    public function singlePage(Request $request)
    {


        if ($request->isPost()){
            $url   = $request->param('url');
            $cate  = $request->param('cate');
            $order = $request->param('order');

            if (strpos($url, 'uumnt')){
                $_rule = '.center>a';
            }

            $html      = file_get_contents($url);
            //         $html = $this->fetch_url_page_contents($first_url);

            $total_img = [];
            $deep      = 0;
            //首页不规则规则制定：UU美图：https://www.uumnt.cc/ https://www.uumnt.cc/dongwu/17089.html https://www.uumnt.cc/dongwu/17089_2.html
            while (strpos($html, '<head><title>404 Not Found</title></head>') === false){
                //             if (strpos($url, 'uumnt') !== false){
                $ql     = QueryList::html($html)->rules(['img' => ['img', 'src']])->range($_rule);
                $result = $ql->query()->getData();
                $_arr   = $result->all();
                $ql->destruct();

                if (count($_arr) > 1){  //每页多图
                    foreach ($_arr as $_v){
                        //优化方法,原方法：$total_img = array_values(array_merge($_v, $total_img));
                        $total_img[] = $_v['img'];
                    }
                }else {                 //每页单图
                    $total_img = $_arr[0]['img'];
                }

                if (strpos($url, 'uumnt')){
                    $deep = $deep == 0 ? $deep + 2 : $deep + 1;
                    $html = $this->fetch_url_page_contents(substr($url, 0, -5).'_'.$deep.'.html');
                }

                $time = time();

                //图片下载+qiniu+销毁+入库
                if (count($total_img) > 1){ //多张图
                    foreach ($total_img as $_value){
                        $real_name = $this->downLoadPic($total_img, 'fake');

                        foreach ($real_name as $_v){
                            $sql_data  = [
                                'cate'   => $cate,
                                'author' => 'internet',
                                'order'  => $order,
                                'thumb'  => 'http://'.$this->qiniuSet($_v, $time),
                                'see'    => random_int(60, 2000),
                                'title'  => $cate.date("Y/m/d/H"),
                                'pic'    => $_value,
                                'time'   => $time,
                            ];
                            db('article')->insert($sql_data);
                        }
                    }
                }else { //单张图
                    $real_name = $this->downLoadPic($total_img, 'fake');

                    $sql_data  = [
                        'cate'   => $cate,
                        'author' => 'internet',
                        'order'  => $order,
                        'thumb'  => 'http://'.$this->qiniuSet($real_name[0], $time),
                        'see'    => random_int(60, 2000),
                        'title'  => $cate.date("Y/m/d/H"),
                        'pic'    => $total_img,
                        'time'   => $time,
                    ];
                    db('article')->insert($sql_data);
                }
                $total_img = [];

                //             }
            }
        }
        $cate = db('category')->field(['id', 'catename'])->order('sort', 'asc')->select();
        return $this->view->fetch('article-do-single', ['cate' => $cate]);
    }

    /**
     * 条目爬取
     * @param Request $request
     * @return string
     */
    public function cateCrawl(Request $request)
    {
        set_time_limit(0);
        //1、预备url，给出最后的url 2、分割url，拼凑并循环每页并得到每页的待爬取url list 3、调用singlepage方法植入title爬取数据
        if ($request->isPost()){

            $form      = $request->param();
            $first_url = $form['first_url'];
            $last_url  = $form['last_url'];
//             $html      = $this->fetch_url_page_contents($form['first_url']);
            $html      = file_get_contents($form['first_url']);

            $baseurl   = parse_url($form['first_url'])['scheme'].'://'.parse_url($form['first_url'])['host']; //构建完整URL

            //https://www.uumnt.cc/shuaige/    https://www.uumnt.cc/shuaige/list_2.html
//            if (strpos($first_url, 'uumnt') != false)
//            {
            $page      = 1;     //初始化page
            if (strpos($first_url, 'list') != false){
                if (preg_match('|(\d+)|',$first_url,$r)) $page = $r[1];
            }
            $last_page = $page; //初始化last_page
            if (!empty($last_url)){
                if (preg_match('|(\d+)|',$last_url,$r)) $last_page = $r[1];   //当last_url存在，则置换last_page的值,即循环体内只循环此页
            }

            for ($page; $page < $last_page+1; $page ++)
            {
                //uumnt站 list循环抓取
                $ql     = QueryList::html($html)->rules(['href' => ['a', 'href'], 'title' => ['.list_h', 'text']])->range('#mainbodypul>div');
                $result = $ql->query()->getData(function($item) use ($baseurl){
                    if (strpos($item['href'], 'http') === false) return [$baseurl.$item['href'], $item['title']];
                    return [$item['href'], $item['title']];
                });

                $ql->destruct();
                $result = $result->all();   //得到页面所有url&title
                foreach ($result as $_value){
                    //爬取当前目录cate页
                    $this->inCrawlPage($_value[0], $form['cate'], $form['order'], $_value[1]);
                }

                if (strpos($first_url, 'html') === false){
                    $first_url = $first_url.'list_'.($page + 1).'.html';
                }else {
                    $first_url = preg_replace('|(\d+)|', ($page + 1), $first_url);
                }

                $html = file_get_contents($first_url);
//                    $html = $this->fetch_url_page_contents($first_url);
            }
        }


//        }

        $cate = db('category')->field(['id', 'catename'])->order('sort', 'asc')->select();
        return $this->view->fetch('article-do-cate', ['cate' => $cate]);
    }

    public function inCrawlPage($url, $cate, $order, $title)
    {
        if (strpos($url, 'uumnt')){
            $_rule = '.center>a';
        }
        $html      = file_get_contents($url);
//         $html = $this->fetch_url_page_contents($first_url);

        $total_img = [];
        $deep      = 0;
        //首页不规则规则制定：UU美图：https://www.uumnt.cc/ https://www.uumnt.cc/dongwu/17089.html https://www.uumnt.cc/dongwu/17089_2.html
        while (strpos($html, '<head><title>404 Not Found</title></head>') === false){
//             if (strpos($url, 'uumnt') !== false){
            $ql     = QueryList::html($html)->rules(['img' => ['img', 'src']])->range($_rule);
            $result = $ql->query()->getData();
            $_arr   = $result->all();
            $ql->destruct();

            if (count($_arr) > 1){  //每页多图
                foreach ($_arr as $_v){
                    //优化方法,原方法：$total_img = array_values(array_merge($_v, $total_img));
                    $total_img[] = $_v['img'];
                }
            }else {                 //每页单图
                $total_img = $_arr[0]['img'];
            }

            if (strpos($url, 'uumnt')){
                $deep = $deep == 0 ? $deep + 2 : $deep + 1;
                $html = $this->fetch_url_page_contents(substr($url, 0, -5).'_'.$deep.'.html');
            }

            $time = time();

            //图片下载+qiniu+销毁+入库
            if (count($total_img) > 1){ //多张图
                foreach ($total_img as $_value){
                    $real_name = $this->downLoadPic($total_img, 'fake');

                    foreach ($real_name as $_v){
                        $sql_data  = [
                            'cate'   => $cate,
                            'author' => 'internet',
                            'title'  => $title,
                            'order'  => $order,
                            'thumb'  => 'http://'.$this->qiniuSet($_v, $time),
                            'see'    => random_int(60, 2000),
                            'pic'    => $_value,
                            'time'   => $time,
                        ];
                        db('article')->insert($sql_data);
                    }
                }
            }else { //单张图
                $real_name = $this->downLoadPic($total_img, 'fake');

                $sql_data  = [
                    'cate'   => $cate,
                    'title'  => $title,
                    'author' => 'internet',
                    'order'  => $order,
                    'thumb'  => 'http://'.$this->qiniuSet($real_name[0], $time),
                    'see'    => random_int(60, 2000),
                    'pic'    => $total_img,
                    'time'   => $time,
                ];
                db('article')->insert($sql_data);
            }
            $total_img = [];

//             }
        }
    }

    /**
     * 得到img和url
     * Tips：目前硬规定为：img和url的rule的数组键必须为img和url
     * @param unknown $url    需要抓取的url
     * @param unknown $html   html实体
     * @param unknown $rule1  img rule
     * @param string $rule2   url rule
     * @param string $baseurl 站点原始url
     * @return boolean|Collection[]
     */
    public function getPageData($url, $html, $img_rule, $url_rule = '', $baseurl = '')
    {
        if (empty($url)) return false;

        //采集并处理为完整url的img
        $ql  = QueryList::html($html)->rules($img_rule);
        $re1 = $ql->query()->getData(function($item) use ($url, $baseurl){
            if (!is_numeric(strpos($item['img'], 'http'))) return $baseurl.$item['img'];
            return $item['img'];
        }); //得到img

        //筛选图片
        $re_img = $re1->all();
        $ql->destruct();    //释放资源
        array_walk($re_img, function(&$value, $key, $str){
            if (!is_numeric(strpos($value, $str))) $value = '';
        }, $this->getHost($baseurl));    //去除非host的img
        $re_img = $this->removeRepeatEmpty($re_img);


        //处理并采集为完整的url
        if (!empty($url_rule)){
            $ql2 = QueryList::html($html)->rules($url_rule);
            $re2 = $ql2->query()->getData(function($item) use ($url, $baseurl){
                if (!is_numeric(strpos($item['url'], 'http'))) return $baseurl.$item['url'];
                return $item['url'];
            }); //得到url

            $re_url = $re2->all();
            $ql2->destruct();    //释放资源
            array_walk($re_url, function(&$value, $key, $str){
                if (!is_numeric(strpos($value, $str))) $value = '';
            }, $this->getHost($baseurl));    //去除非host的url

            $re_url = $this->removeRepeatEmpty($re_url);
        }

        if (!isset($re_url)) $re_url = [];

        return [$re_img, $re_url];
    }


    /**
     * 得到最后的host主机名
     * @param unknown $url
     */
    public function getHost($url)
    {
        $arr = array_slice(explode('.', parse_url($url)['host']),-2,1);
        return $arr[0];
    }

    /**
     * 数组去空和去重
     * @param unknown $arr
     */
    public function removeRepeatEmpty($arr)
    {
        $not_null = array_filter($arr);
        return array_unique($not_null);
    }

    /**
     * 返回图片名
     * @param unknown $link
     * @param unknown $dir
     * @return boolean|string
     */
    public function downLoadPic($link, $dir)
    {
        $file_name = [];
        if (is_array($link)){
            foreach ($link as $_v){
                $file_name[] = $this->getimg1($_v, $dir);
                ob_flush();
                flush();
            }
        }else {
            $file_name[] = $this->getimg1($link, $dir);
            ob_flush();
            flush();
        }
        return $file_name;
    }
    
    /**
     * pic新抓取方法
     * @param unknown $url
     * @param unknown $filepath
     * @return boolean|string
     */
    public function getimg1($url, $filepath)
    {
        if ($url == '') return false;
        
        $ext = strrchr($url, '.');
        if ($ext != '.gif' && $ext != '.jpg' && $ext != '.png') return false;
        
        //判断路经是否存在
        !is_dir($filepath) ? mkdir($filepath) : null;
        
        //获得随机的图片名，并加上后辍名
        $filetime = time();
        $filename = date("Y-m-d-H-i-s", $filetime).'-'.rand(100,999).'.'.substr($url,-3,3);
        
        $remote_fp = fopen($url,'rb');
        $local_fp  = fopen($filepath.'/'.$filename, 'wb');
        
        while(!feof($remote_fp)){
            fwrite($local_fp,fread($remote_fp,128));
        }
        
        fclose($remote_fp);
        fclose($local_fp);
        
        return ROOT_PATH . 'public' . DS . $filepath . DS . $filename;
    }


    /**
     * 上传qiniu
     * @param unknown $filePath
     */
    public function qiniuSet($filePath, $time)
    {
        //获取后缀
        $ext = explode('.', $filePath)[1];
        //上传到七牛后保存的文件名(加盐)
        $key = config('qiniu.salt').$time.'_'.substr(md5($filePath) , 0, 5). date('YmdHis') . rand(0, 9999) . '.' . $ext;
        //构建鉴权对象
        $auth = new Auth(config('qiniu.ak'), config('qiniu.sk'));
        //要上传的空间
        $token = $auth->uploadToken(config('qiniu.bucket'));
        //初始化uploadmanager对象并进行文件的上传
        $uploadMgr = new UploadManager();
        //调用uploadmanager的putfile方法进行文件的上传
        list($ret, $err) = $uploadMgr->putFile($token, $key, $filePath);
        $_data['pic']    = config('qiniu.domain').'/'.$ret['key'];
        $err ? $_data['thumb'] = '图片上传失败' : $_data['thumb'] = config('qiniu.domain').'/'.$ret['key'];

        //上传后删除本地图片
        if (file_exists($filePath)) @unlink($filePath);
        return $_data['thumb'];
//        E:\programinstall\xampp\tmp\php52B.tmp
//        E:\programinstall\xampp\htdocs\Claire\public\uploads\fake\2018-05-26-23-36-21-374.jpg
    }


}
