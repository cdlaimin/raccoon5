<?php
/**
 * Created by PhpStorm.
 * User: hiliq
 * Date: 2019/3/1
 * Time: 21:19
 */

namespace app\api\controller;

use app\model\Author;
use app\model\Book;
use app\model\Photo;
use think\Controller;
use think\Request;
use app\model\Chapter;
use app\service\chapterService;
use app\service\photoService;

class Postbot
{
    protected $chapterService;
    protected $photoService;


    public function initialize()
    {
        $this->chapterService = new \app\service\ChapterService();
        $this->photoService = new \app\service\PhotoService();
    }

    public function save(Request $request)
    {
        if ($request->isPost()) {
            $data = $request->param();
            $key = $data['api_key'];
            if (empty($key) || is_null($key)) {
                return 'api密钥不能为空！';
            }
            if ($key != config('site.api_key')) {
                return 'api密钥错误！';
            }

            $book = Book::where('book_name', '=', trim($data['book_name']))->where('unique_id', '=', trim($data['unique_id']))->find();
            if (!$book) { //如果漫画不存在
                $author = Author::where('author_name', '=', trim($data['author']))->find();
                if (is_null($author)) {//如果作者不存在
                    $author = new Author();
                    $author->author_name = $data['author'] ?: '侠名';
                    $author->save();
                }
                $book = new Book();
                $book->unique_id = $data['unique_id'];
                $book->author_id = $author->id;
                $book->author_name = $data['author'] ?: '侠名';
                $book->area_id = trim($data['area_id']);
                $book->book_name = trim($data['book_name']);
                if (!empty($data['nick_name']) || !is_null($data['nick_name'])) {
                    $book->nick_name = trim($data['nick_name']);
                }
                $book->tags = trim($data['tags']);
                $book->end = trim($data['end']);
                $book->start_pay = trim($data['start_pay']);
                $book->money = trim($data['money']);
                $info = $this->getImage($data['cover_url'], './img/' . date("Ymd"));
                $book->cover_url = trim($info['save_path']);
                $book->summary = trim($data['summary']);
                $book->last_time = time();
                //      $book->update_week = rand(1, 7);
                //      $book->click = rand(1000, 9999);
                $book->save();
            }
            $map[] = ['chapter_name', '=', trim($data['chapter_name'])];
            $map[] = ['book_id', '=', $book->id];
            $chapter = Chapter::where($map)->find();
            if (!$chapter) {
                $chapter = new Chapter();
                $chapter->chapter_name = trim($data['chapter_name']);
                $chapter->book_id = $book->id;
                $lastChapterOrder = 0;
                $lastChapter = $this->getLastChapter($book['id']);
                if ($lastChapter) {
                    $lastChapterOrder = $lastChapter->chapter_order;
                }
                $chapter->chapter_order = $lastChapterOrder + 1;
                $chapter->update_time = time();
                $chapter->save();
            }
            $book->last_time = time();
            $book->save();
            $preg = '/\bsrc\b\s*=\s*[\'\"]?([^\'\"]*)[\'\"]?/i';
            preg_match_all($preg, $data['images'], $img_urls);
            $lastOrder = 0;
            $lastPhoto = $this->getLastPhoto($chapter->id);
            if ($lastPhoto) {
                $lastOrder = $lastPhoto->pic_order + 1;
            }
            foreach ($img_urls[1] as $img_url) {
                $photo = new Photo();
                $photo->chapter_id = $chapter->id;
                $photo->pic_order = $lastOrder;
                $info = $this->getImage($img_url, './img/' . date("Ymd"));

                $photo->img_url = $info['save_path'];
                $photo->save();
                $lastOrder++;
            }
            echo "操作成功";
        }

    }

    public function getLastChapter($book_id)
    {
        return Chapter::where('book_id', '=', $book_id)->order('chapter_order', 'desc')->limit(1)->find();
    }

    public function getLastPhoto($chapter_id)
    {
        return Photo::where('chapter_id', '=', $chapter_id)->order('id', 'desc')->limit(1)->find();
    }

    /*功能：php完美实现下载远程图片保存到本地
  *参数：文件url,保存文件目录,保存文件名称，使用的下载方式
  *当保存文件名称为空时则使用远程文件原来的名称
  */
    function getImage($url, $save_dir = '/public/img/', $filename = '', $type = 1)
    {
        if (trim($url) == '') {
            return array('file_name' => '', 'save_path' => '', 'error' => 1);
        }
        if (trim($save_dir) == '') {
            $save_dir = './';
        }

        $url = str_replace('jpg\r', 'jpg', $url);

        file_put_contents("result.txt", "url：" . $url . "\r\n", FILE_APPEND);


        if (trim($filename) == '') {//保存文件名
            $ext = strrchr($url, '.');
//            if($ext!='.gif'&&$ext!='.jpg'){
//                return array('file_name'=>'','save_path'=>'','error'=>3);
//            }
            $filename = time() . rand(100000, 999999) . $ext;
        }
        if (0 !== strrpos($save_dir, '/')) {
            $save_dir .= '/';
        }
        //创建保存目录
        if (!file_exists($save_dir) && !mkdir($save_dir, 0777, true)) {
            return array('file_name' => '', 'save_path' => '', 'error' => 5);
        }
        //获取远程文件所采用的方法
        if ($type) {
            $img =$this-> http_curl($url);
            !$img && $img = $this->http_curl($url);
            !$img && $img = $this->http_curl($url);
        } else {
            ob_start();
            readfile($url);
            $img = ob_get_contents();
            ob_end_clean();
        }
        //$size=strlen($img);

//        dump($img);die;/
        //文件大小

//        dump($save_dir.$filename);die;
        $fp2 = @fopen($save_dir . $filename, 'a');
        fwrite($fp2, $img);
        fclose($fp2);
        unset($img, $url);
        return array('file_name' => $filename, 'save_path' => str_replace('./', '/', $save_dir . $filename), 'error' => 0);
    }


    function http_curl($url)
    {

        $ch = curl_init();
        $timeout = 5;
        $aHeader = array('Referer: https://www.baidu.com/');
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $aHeader);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $img = curl_exec($ch);
        curl_close($ch);
        return $img;
    }


}