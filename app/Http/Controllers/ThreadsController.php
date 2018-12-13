<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

use App\Http\Requests\StoreThread;
use App\Sosadfun\Traits\ThreadTraits;

use App\Models\Thread;
use App\Models\Post;
use App\Models\Tag;
use App\Models\Channel;
use App\Models\RecommendBook;
use Carbon\Carbon;
use Auth;
use App\Models\User;
use App\Helpers\Helper;
// use App\RegisterHomework;

class threadsController extends Controller
{
    use ThreadTraits;

    public function __construct()
    {
        $this->middleware('auth')->except(['index', 'show', 'showpost']);
    }

    public function index(Request $request)
    {
        $group = 10;
        $logged = Auth::check()? true:false;
        if(Auth::check()){$group = Auth::user()->group;}
        $threadqueryid = '-tQry-'.$group
        .($logged?'Lgd':'nLg')
        .($request->label? 'L'.$request->label:'')
        .($request->channel? 'Ch'.$request->channel:'')
        .(is_numeric($request->page)? 'P'.$request->page:'P1');
        $threads = Cache::remember($threadqueryid, 2, function () use($group, $request, $logged) {
            $query = $this->join_no_book_thread_tables()
            ->where([['threads.book_id','=',0],['threads.deleted_at', '=', null],['channels.channel_state','<',$group],['threads.public','=',1]]);
            if($request->label){$query = $query->where('threads.label_id','=',$request->label);}
            if($request->channel){$query = $query->where('threads.channel_id','=',$request->channel);}
            if(!$logged){$query = $query->where('threads.bianyuan','=',0);}
            $threads = $this->return_no_book_thread_fields($query)
            ->orderby('threads.lastresponded_at', 'desc')
            ->paginate(config('constants.index_per_page'))
            ->appends($request->query());
            return $threads;
        });
        return view('threads.index', compact('threads'))->with('show_as_collections', false)->with('show_channel',true)->with('active',1);
    }

    public function show(Thread $thread, Request $request)
    {
        if (request('recommendation')){
            $recommendation = RecommendBook::find(request('recommendation'));
            if($recommendation){
                $recommendation->increment('clicks');
            }
        }
        $channel = Helper::allChannels()->keyBy('id')->get($thread->channel_id);
        $label = Helper::allLabels()->get($thread->label_id);
        $posts = Post::allPosts($thread->id,$thread->post_id)->userOnly(request('useronly'))->withOrder('oldest')
        ->with('owner','reply_to_post.owner','comments.owner')->paginate(config('constants.items_per_page'));
        $posts->load('chapter');

        if(!Auth::check()||(Auth::id()!=$thread->user_id)){
            $thread->increment('viewed');
        }
        $book = [];
        $xianyus = [];
        $shengfans = [];
        if ($thread->book_id>0){
            $book = $thread->book;
        }
        if((!request()->page)||(request()->page == 1)){
            //dd('front page');
            $thread->load('mainpost','creator');
            $xianyus = Cache::remember('-t'.$thread->id.'-xianyus', 10, function () use($thread) {
                $xianyus = $thread->xianyus;
                $xianyus->load('creator');
                return $xianyus;
            });

            $shengfans = Cache::remember('-t'.$thread->id.'-shengfans', 10, function () use($thread) {
                $shengfans = $thread->mainpost->shengfans;
                $shengfans->load('creator');
                return $shengfans;
            });
        }

        //dd($thread->homework->registered_students());
        return view('threads.show', compact('thread', 'posts','book','xianyus','shengfans','channel','label'))->with('defaultchapter',0)->with('chapter_replied',true)->with('show_as_book',false);
    }

    public function createThreadForm(Channel $channel)
    {
        $labels = $channel->labels();
        if ($channel->id<=2){
            return view('books.create');
        }
        return view('threads.create', compact('labels', 'channel'));
    }

    public function store(StoreThread $form, Channel $channel)
    {
        $thread = $form->generateThread($channel->id);
        $thread->user->reward("regular_thread");
        if($thread->label_id == 50){
            $thread->registerhomework();
        }
        return redirect()->route('thread.show', $thread->id)->with("success", "您已成功发布主题");
    }

    public function edit(Thread $thread)
    {
        if ((Auth::user()->admin)||($thread->user_id == Auth::id()&&(!$thread->locked)&&($thread->channel->channel_state!=2))){
            return view('threads.edit', compact('thread'));
        }else{
            return redirect()->back()->with("danger","本版面无法编辑内容");
        }

    }

    public function update(StoreThread $form, Thread $thread)
    {
        if ((Auth::id() == $thread->user_id)&&(!$thread->locked)){
            $form->updateThread($thread);
            return redirect()->route('thread.show', $thread->id)->with("success", "您已成功修改主题");
        }else{
            return redirect()->route('error', ['error_code' => '403']);
        }
    }
    public function showpost(Post $post, Request $request)
    {
        if (request('recommendation')){
            $recommendation = RecommendBook::find(request('recommendation'));
            if($recommendation){
                $recommendation->increment('clicks');
            }
        }
        $thread = $post->thread;
        $totalposts = Post::allPosts($thread->id,$thread->post_id)
        ->where('created_at', '<', $post->created_at)
        ->count();
        $page = intdiv($totalposts, config('constants.items_per_page'))+1;
        $url = 'threads/'.$thread->id.'?page='.$page.'#post'.$post->id;
        return redirect($url);
    }
}
