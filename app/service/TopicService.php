<?php


namespace app\service;


use app\model\Topic;

class TopicService
{
    public function getPagedAdmin($where = '1=1'){
        $data = Topic::where($where);
        $page = config('page.back_end_page');
        $topics = $data->order('id','desc')
            ->paginate(
                [
                    'list_rows'=> $page,
                    'query' => request()->param(),
                    'var_page' => 'page',
                ]);
        return [
            'topics' => $topics,
            'count' => $data->count()
        ];
    }
}