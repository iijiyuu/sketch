<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use DB;
use Cache;
use Carbon;
use ConstantObjects;
use App\Models\Post;
use App\Models\Thread;
use CacheUser;
use App\Http\Requests\StoreThread;
use Auth;
use StringProcess;
use App\Sosadfun\Traits\ThreadObjectTraits;
use App\Sosadfun\Traits\PostObjectTraits;
use App\Sosadfun\Traits\ThreadQueryTraits;

class threadsController extends Controller
{
    use ThreadObjectTraits;
    use PostObjectTraits;
    use ThreadQueryTraits;

    public function __construct()
    {
        $this->middleware('auth')->only('create','store','edit','update');
    }

    public function index(Request $request)
    {
        $request_data = $this->sanitize_request_data($request);

        $query_id = $this->process_thread_query_id($request_data);

        $threads = $this->find_threads_with_query($query_id, $request_data);

        return view('threads.filter', compact('threads'));
    }

    public function thread_index(Request $request)
    {
        $page = is_numeric($request->page)? $request->page:'1';
        $threads = Cache::remember('thread_index_P'.$page.url('/'), 2, function () use($page) {
            return $threads = Thread::with('author', 'tags', 'last_component', 'last_post')
            ->isPublic()
            ->inPublicChannel()
            ->withoutType('book')
            ->ordered()
            ->paginate(config('preference.threads_per_page'))
            ->appends(['page'=>$page]);
        });
        $simplethreads = $this->jinghua_threads();

        return view('threads.thread_index', compact('threads','simplethreads'))->with('threads_tab','index');
    }

    public function thread_jinghua(Request $request)
    {
        $page = is_numeric($request->page)? $request->page:'1';
        $jinghua_tag = ConstantObjects::find_tag_by_name('精华');
        $threads = Cache::remember('thread_jinghua_P'.$page.url('/'), 10, function () use($page, $jinghua_tag) {
            return $threads = Thread::with('author', 'tags', 'last_component', 'last_post')
            ->isPublic()//复杂的筛选
            ->inPublicChannel()
            ->withTag($jinghua_tag->id)
            ->ordered()
            ->paginate(config('preference.threads_per_page'))
            ->appends(['page'=>$page]);
        });

        return view('threads.thread_jinghua', compact('threads'))->with('threads_tab','jinghua');
    }

    public function channel_index($channel, Request $request)
    {
        $channel = collect(config('channel'))->keyby('id')->get($channel);
        $primary_tags = ConstantObjects::extra_primary_tags_in_channel($channel->id);

        $queryid = 'channel-index'
        .url('/')
        .'-ch'.$channel->id
        .'-withBianyuan'.$request->withBianyuan
        .'-withTag'.$request->withTag
        .'-ordered'.$request->ordered
        .(is_numeric($request->page)? 'P'.$request->page:'P1');

        $threads = Cache::remember($queryid, 2, function () use($request, $channel) {
            return $threads = Thread::with('author', 'tags', 'last_component', 'last_post')
            ->isPublic()
            ->inChannel($channel->id)
            ->withBianyuan($request->withBianyuan)
            ->withTag($request->withTag)
            ->ordered($request->ordered)
            ->paginate(config('preference.threads_per_page'))
            ->appends($request->only('withBianyuan', 'ordered', 'withTag','page'));
        });

        $simplethreads = $this->find_top_threads_in_channel($channel->id);

        return view('threads.thread_channel', compact('channel', 'threads', 'simplethreads', 'primary_tags'));
    }



    public function create(Request $request)
    {
        $user = CacheUser::Auser();
        $channel = collect(config('channel'))->keyby('id')->get($request->channel_id);

        if(!$user||empty($channel)||((!$channel->is_public)&&(!$user->canSeeChannel($channel->id)))){abort(403);}

        if(Cache::has('created-thread-' . $user->id)){
            return redirect('/')->with('danger', '你在10分钟内已成功建立过新主题，请查询个人主题记录，勿重复建立主题。');
        }

        if($user->no_posting){
            return back()->with('danger','你被禁言中，无法创建主题');
        }

        $tags = ConstantObjects::primary_tags_in_channel($channel->id);

        if($channel->type==='list'){
            $list_count = Thread::where('user_id', $user->id)->withType('list')->count();
            if(($list_count > $user->level-4)&&!$user->isAdmin()&&!$user->isEditor()){
                return redirect()->back()->with('warning','你的收藏单数量已达上线，不能再建立');
            }
        }
        if($channel->type==='box'){
            $box_count = Thread::where('user_id', auth('api')->id())->withType('box')->count();
            if($box_count >=1){
                return redirect()->back()->with('warning','每个人只能建立一个问题箱，你已经建立了问题箱');
            }
        }

        if($channel->type==='book'){
            if($user->level<1||$user->quiz_level<1){
                return redirect()->back()->with('warning','你的用户等级/答题等级不足，目前不能建立书籍');
            }
            return view('books.create');
        }

        if($user->level<4||$user->quiz_level<2){
            return redirect()->back()->with('warning','你的用户等级/答题等级不足，目前不能建立讨论帖');
        }

        return view('threads.create', compact('channel','tags'));
    }

    public function store(StoreThread $form)
    {
        $channel = collect(config('channel'))->keyby('id')->get($form->channel_id);
        $user = CacheUser::Auser();
        $info = CacheUser::Ainfo();

        if(!$user||$user->no_posting||empty($channel)||((!$channel->is_public)&&(!$user->canSeeChannel($channel->id)))){abort(403);}

        if(Cache::has('created-thread-' . $user->id)){
            return redirect('/')->with('danger', '你在10分钟内已成功建立过新主题，请查询个人主题记录，勿重复建立主题。');
        }

        if($channel->type==='list'){
            $list_count = Thread::where('user_id', $user->id)->withType('list')->count();
            if(($list_count > $user->level-4)&&!$user->isAdmin()&&!$user->isEditor()){abort(403);}
        }

        if($channel->type==='box'){
            $box_count = Thread::where('user_id', $user->id)->withType('box')->count();
            if($box_count >=1){abort(403);}
        }

        if($channel->type==='book'){
            abort(403);
        }

        if($user->level<4){
            abort(403);
        }

        $thread = $form->generateThread($channel);

        $thread->tags()->syncWithoutDetaching($thread->tags_validate(array($form->tag)));

        $thread->user->reward("regular_thread");
        // if($channel->type==='homework'){
        //     $thread->register_homework();
        // }
        if($thread->channel()->type==='list'&&$info->default_list_id===0){
            $info->update(['default_list_id'=>$thread->id]);
        }
        if($thread->channel()->type==='box'&&$info->default_box_id===0){
            $info->update(['default_box_id'=>$thread->id]);
        }

        Cache::put('created-thread-' . $user->id, true, Carbon::now()->addMinutes(10));

        $thread = $this->threadProfile($thread->id);

        return redirect()->route('thread.show', $thread->id)->with("success", "你已成功发布主题");
    }

    public function edit($id)
    {
        $thread = Thread::on('mysql::write')->find($id);

        $user = CacheUser::Auser();
        $channel = $thread->channel();

        if(empty($channel)||((!$channel->is_public)&&(!$user->canSeeChannel($channel->id)))){abort(403);}

        if ((Auth::user()->isAdmin())||($thread->user_id == Auth::id()&&(!$thread->is_locked)&&($thread->channel()->allow_edit))){

            $selected_tags = $thread->tags;

            $tags = ConstantObjects::primary_tags_in_channel($channel->id);

            return view('threads.edit', compact('channel', 'thread','user','tags','selected_tags'));
        }else{
            return redirect()->back()->with("danger","本版面无法编辑内容");
        }
    }

    public function update($id, StoreThread $form)
    {
        $thread = Thread::on('mysql::write')->find($id);

        if ((Auth::id() == $thread->user_id)&&((!$thread->is_locked)||(Auth::user()->isAdmin()))){

            $form->updateThread($thread);

            $thread->keep_only_admin_tags();
            $thread->tags()->syncWithoutDetaching($thread->tags_validate(array($form->tag)));

            $this->refreshThread($thread->id);
            return redirect()->route('thread.show', $thread->id)->with("success", "你已成功修改主题");
        }else{
            abort(403);
        }
    }
    public function showpost($id)
    {
        $post = $this->findPost($id);

        if(!$post){abort(404);}

        $withFolded='';
        $withComponent='';
        if($post->fold_state){
            $withFolded = 'include_folded';
        }
        if($post->type==='comment'){
            $withComponent = 'include_comment';
        }
        $previousposts = Post::where('thread_id',$post->thread_id)
        ->withComponent($withComponent)
        ->withFolded($withFolded)
        ->where('created_at', '<', $post->created_at)
        ->count();

        $page = intdiv($previousposts, config('preference.posts_per_page'))+1;
        $url = 'threads/'.$post->thread_id.'?page='.$page.'&withFolded='.$withFolded.'&withComponent='.$withComponent.
        '#post'.$post->id;
        return redirect($url);
    }

    public function chapter_index($id)
    {
        $thread = $this->threadProfile($id);
        return view('chapters.chapter_index', compact('thread'));
    }

    public function review_index($id)
    {
        $thread = $this->threadProfile($id);
        return view('reviews.review_index', compact('thread'));
    }

    public function show_profile($id, Request $request)
    {
        $thread = $this->threadProfile($id);
        $posts = $this->threadProfilePosts($id);
        $user = Auth::check()? CacheUser::Auser():'';
        $info = Auth::check()? CacheUser::Ainfo():'';
        $thread->recordCount('view', 'thread');
        $thread->recordViewHistory();
        return view('threads.show_profile', compact('thread', 'posts','user','info'));
    }

    public function show($id, Request $request)
    {
        $show_config = $this->decide_thread_show_config($request);
        if($show_config['show_profile']){
            $thread = $this->threadProfile($id);
        }else{
            $thread = $this->findThread($id);
        }
        $thread->recordCount('view', 'thread');
        $thread->recordViewHistory();

        $request_data = $this->sanitize_thread_post_request_data($request);

        $query_id = $this->process_thread_post_query_id($request_data);

        $posts = $this->find_thread_posts_with_query($id, $query_id, $request_data);

        $withReplyTo = '';
        if($request->withReplyTo>0){
            $withReplyTo = $this->findPost($request->withReplyTo);
            if($withReplyTo&&$withReplyTo->thread_id!=$thread->id){
                $withReplyTo = '';
            }
        }
        $inComponent = '';
        if($request->inComponent>0){
            $inComponent = $this->findPost($request->inComponent);
            if($inComponent&&$inComponent->thread_id!=$thread->id){
                $inComponent = '';
            }
        }

        $channel = $thread->channel();
        if($channel->type==='book'){
            $posts->load('chapter');
        }
        if($channel->type==='list'){
            $posts->load('review.reviewee');
        }
        $user = Auth::check()? CacheUser::Auser():'';
        $info = Auth::check()? CacheUser::Ainfo():'';
        $selector = config('selectors')[$channel->type.'_filter'];

        return view('threads.show', compact('show_config', 'thread', 'posts', 'user', 'info', 'selector', 'withReplyTo','inComponent'));
    }
}
