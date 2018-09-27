<?php

function version() {
    $major = "1";
    $minor = trim(exec('git rev-list HEAD | wc -l'));
    $hash = trim(exec('git log --pretty="%h" -n1 HEAD'));
    $date = new \DateTime(trim(exec('git log -n1 --pretty=%ci HEAD')));
    $date->setTimezone(new \DateTimeZone('UTC'));

    return sprintf('v%s.%s-%s (%s)', $major, $minor, $hash, $date->format('Y-m-d H:i:s'));
}

if (isset($_GET) && count($_GET)) {

    $action = (isset($_GET['action']) ? $_GET['action'] : "");
    //$page = (isset($_GET['page']) ? $_GET['page'] : 1);
    $response = new stdClass;

    session_start();

    require_once('vendor/autoload.php');
    require_once("config.php");

    $tmpToken = isset($_SESSION['tmp_oauth_token'])? $_SESSION['tmp_oauth_token'] : null;
    $tmpTokenSecret = isset($_SESSION['tmp_oauth_token_secret'])? $_SESSION['tmp_oauth_token_secret'] : null;
    $client = new Tumblr\API\Client($consumerKey, $consumerSecret, $tmpToken, $tmpTokenSecret);
    // Change the base url
    $requestHandler = $client->getRequestHandler();
    $requestHandler->setBaseUrl('https://www.tumblr.com/');
    if (!empty($_GET['oauth_verifier'])) {
        // exchange the verifier for the keys
        $verifier = trim($_GET['oauth_verifier']);
        $resp = $requestHandler->request('POST', 'oauth/access_token', array('oauth_verifier' => $verifier));
        $out = (string) $resp->body;
        $data = array();
        parse_str($out, $data);
        unset($_SESSION['tmp_oauth_token']);
        unset($_SESSION['tmp_oauth_token_secret']);
        $_SESSION['Tumblr_oauth_token'] = $data['oauth_token'];
        $_SESSION['Tumblr_oauth_token_secret'] = $data['oauth_token_secret'];
        header('Location: ./');
        exit;
    }

    if ( !(empty($_SESSION['Tumblr_oauth_token']) || empty($_SESSION['Tumblr_oauth_token_secret'])) ) {

        $client = new Tumblr\API\Client(
            $consumerKey,
            $consumerSecret,
            $_SESSION['Tumblr_oauth_token'],
            $_SESSION['Tumblr_oauth_token_secret']
        );

        $options['limit'] = 20;
        switch ($action) {
            case "dash":
            case "blog":
            case "likes":
            case "tagged":
                //$options['offset']=($page-1)*$options['limit'];
                $options['reblog_info'] = true;
                switch ($action) {
                    case "dash":
                    case "blog":
                        if (isset($_GET['before'])) {
                            $options['before_id'] = $_GET['before'];
                        }
                        if (isset($_GET['type']) && ($_GET['type'] == "photo" || $_GET['type'] == "video")) {
                            $options['type'] = $_GET['type'];
                        }
                    break;
                    case "likes":
                    case "tagged":
                        if (isset($_GET['before'])) {
                            $options['before'] = $_GET['before'];
                        }
                    break;
                }

                do{//start of slides collection
                switch ($action) {
                    case "dash":
                        $result = $client->getDashboardPosts($options);
                        $posts = $result->posts;
                    break;
                    case "blog":
                        if (isset($_GET['tag']) && !empty($_GET['tag'])) {
                            $options['tag'] = $_GET['tag'];
                        }
                        if (isset($_GET['blog'])) {
                            $blog = $_GET['blog'];
                        } else {
                            $code = 400;
                        }
                        try {
                            $result = $client->getBlogPosts($blog, $options);
                            $posts = $result->posts;
                        } catch (Exception $e) {
                            $code = $e->getCode();
                            $posts = [];
                        }
                    break;
                    case "likes":
                        //$clientInfo = $client->getUserInfo();
                        //$likes = $clientInfo->user->likes;
                        //$totalPages = intval($likes / $options['limit']) + ($likes % $options['limit'] > 0 ? 1 : 0);
                        //if ($page <= $totalPages) {
                            $result = $client->getLikedPosts($options);
                            $posts = $result->liked_posts;
                        //} else {
                        //    $code = 400;
                        //}
                    break;
                    case "tagged":
                        if (isset($_GET['tag'])) {
                            $tag = $_GET['tag'];
                        } else {
                            $code = 400;
                        }
                        try {
                            $result = $client->getTaggedPosts($tag, $options);
                            $posts = $result;
                        } catch (Exception $e) {
                            $code = $e->getCode();
                            $posts = [];
                        }
                    break;
                }
                $response->posts = [];
                foreach ($posts as $post) {
                    if (isset($_GET['own']) && $_GET['own']=="1" && isset($post->reblogged_from_name)) continue;
                    /*if (isset($_GET['own']) && $_GET['own']=="1" && isset($post->reblogged_from_name)) {
                        continue;
                    }*/
                    $obj = new stdClass;
                    $obj->blog_name = $post->blog_name;
                    $obj->type = $post->type;
                    $obj->id = $post->id;
                    $obj->post_url = $post->post_url;
                    $obj->timestamp = $post->timestamp;
                    $obj->reblog_key = $post->reblog_key;
                    $obj->liked_timestamp = (isset($post->liked_timestamp) ? $post->liked_timestamp : "" );
                    $obj->rebloged_from = (isset($post->reblogged_from_name) ? $post->reblogged_from_name : "" );
                    $obj->source = (isset($post->reblogged_root_name) ? $post->reblogged_root_name : "" );
                    $obj->tags = (isset($post->tags) ? $post->tags : "" );
                    $obj->caption = (isset($post->caption) ? $post->caption : "" );
                    switch ($post->type) {
                        case "photo":
                            foreach ($post->photos as $photo) {
                                $obj->src = $photo->original_size->url;
                                $response->posts[] = clone $obj;
                            }
                            break;
                        case "video":
                            $obj->video_type = $post->video_type;
                            $obj->html5_capable = (isset($post->html5_capable) ? $post->html5_capable : "" );
                            $obj->player = (isset($post->player) ? $post->player[count($post->player)-1]->embed_code : "" );
                            $obj->video_url = (isset($post->video_url) ? $post->video_url : "" );
                            $response->posts[] = clone $obj;
                            break;
                        /*case "text":
                            $dom = new DOMDocument;
                            libxml_use_internal_errors(true);
                            $dom->loadHTML(((isset($post->body) && !empty($post->body)) ? $post->body : "<p></p>" ));
                            libxml_clear_errors();
                            $images = $dom->getElementsByTagName('img');
                            if (!empty($images)) {
                            $obj->type = "photo";
                                foreach ($images as $image) {
                                    $obj->src = $image->getAttribute('src');
                                    $response->posts[] = clone $obj;
                                }
                            }
                            break;*/
                        default:
                            break;
                    }
                }
                /*if (isset($_GET['own']) && $_GET['own']=="1") {
                    $response->last_id = end($posts)->id;
                    $code = 203;
                }*/
                $last_post = end($posts);
                if (empty($response->posts) && !empty($posts)) {
                    switch ($action) {
                        case "dash":
                        case "blog":
                            $options['before_id'] = $last_post->id;
                        break;
                        case "likes":
                        case "tagged":
                            $options['before'] = $last_post->liked_timestamp;
                        break;
                    }
                }
                } while (empty($response->posts) && !empty($posts));//end of slides collection
                //} while (count($response->posts)<10);//end of slides collection

                if (isset($last_post->id)) $response->last_id = $last_post->id;
                if (isset($last_post->liked_timestamp)) $response->last_liked_timestamp = $last_post->liked_timestamp;

                $code = isset($code) ? $code : 200;
                break;
            case "followed":
                $obj = new stdClass;
                $clientInfo = $client->getUserInfo();
                $obj->total_blogs = $clientInfo->user->following;
                $obj->blogs = [];
                $totalPages = intval($obj->total_blogs / $options['limit']) + ($obj->total_blogs % $options['limit'] > 0 ? 1 : 0);
                for ($page = 1; $page<=$totalPages; $page++) {
                    $options['offset']=($page-1)*$options['limit'];
                    $followedBlogs = $client->getFollowedBlogs($options);
                    /*
                    foreach ($followedBlogs->blogs as $followedBlog) {
                        $followedBlog->avatar = $client->getBlogAvatar($followedBlog->name, 16);
                        $obj->blogs[] = $followedBlog;
                    }
                    */
                    $obj->blogs = array_merge($obj->blogs,$followedBlogs->blogs);
                }
                $response->followed_blogs = $obj;
                $code = 200;
                break;
            case "like":
            case "unlike":
                if (isset($_GET['id']) && isset($_GET['reblog_key'])) {
                    try {
                        switch ($action) {
                            case "like":
                                $result = $client->like($_GET['id'], $_GET['reblog_key']);
                                $msg = "Liked!";
                            break;
                            case "unlike":
                                $result = $client->unlike($_GET['id'], $_GET['reblog_key']);
                                $msg = "Unliked!";
                            break;
                        }
                        $code = 201;
                    } catch (Exception $e) {
                        $code = $e->getCode();
                    }
                } else {
                    $code = 400;
                }
                break;
            case "follow":
                if (isset($_GET['blog'])) {
                    try {
                        $result = $client->follow($_GET['blog']);
                        $code = 202;
                    } catch (Exception $e) {
                        $code = $e->getCode();
                    }
                } else {
                    $code = 400;
                }
                break;
            default:
                $code = 405;
                break;
        }
    } else {
        // start the old gal up
        $callbackUrl = $_SERVER['REQUEST_SCHEME']."://".$_SERVER['SERVER_NAME'].dirname($_SERVER['SCRIPT_NAME']);
        $resp = $requestHandler->request('POST', 'oauth/request_token', array('oauth_callback' => $callbackUrl));
        // Get the result
        $result = (string) $resp->body;
        parse_str($result, $keys);
        $_SESSION['tmp_oauth_token'] = $keys['oauth_token'];
        $_SESSION['tmp_oauth_token_secret'] = $keys['oauth_token_secret'];
        $url = 'https://www.tumblr.com/oauth/authorize?oauth_token=' . $keys['oauth_token'];
        $code = 511;
    }

    switch ($code) {
        case 200:
            $response->msg = "OK";
            break;
        case 201:
            $response->msg = $msg;
            break;
        case 202:
            $response->msg = "Following";
            break;
        case 203:
            $response->msg = "Own posts. Last id";
            break;
        case 400:
            $response->msg = "Bad Request";
            break;
        case 404:
            $response->msg = "Not Found";
            break;
        case 405:
            $response->msg = "Method Not Allowed";
            break;
        case 511:
            $response->auth_url = $url;
            $response->msg = "Authorization Required";
            break;
        default:
            $response->msg = isset($msg) ? $msg : "Unknown Error";
            break;
    }

    $response->code = $code;

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response ,JSON_UNESCAPED_UNICODE);

    exit;
}
?>
<!DOCTYPE html>
<!-- <?php echo version();?> -->
<html>
<head>
  <title>Tumblr Photo Video Slider</title>
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta charset="utf-8">
  <meta name="description" content="">
  <meta name="author" content="">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <meta http-equiv="Cache-Control" content="max-age=3600, must-revalidate">
  <meta name="theme-color" content="#222222" />
  <link rel="icon" href="/img/tpvs16.png" type="image/png">
  <!--<link rel="stylesheet" type="text/css" href="style.css">-->
  <script type="text/javascript" src="js/js.cookie.js"></script>
  <script type="text/javascript" src="js/jquery-3.3.1.min.js"></script>
  <script type="text/javascript">
  $(document).ready(function(){
    console.log("Doc ready");
    var currentLayout;

    var layouts = [];

    var layout$ = {
        // Properties
        layoutType: "dash",
        blog:"",
        type:"all",
        own:"0",
        currentSlide:0,
        //currentPage:0,
        before:"",
        tag:"",
        slides: [],
        noMore: false,
        iframe: null,
        locked: false,
        wasHidden: false,
        updateLocked: false,
        // Methods
        save: function(){
        console.log("Saving.");
            Cookies.set("layoutType", this.layoutType, { expires : 0.5 });
            Cookies.set("blog", this.blog, { expires : 0.5 });
            Cookies.set("type", this.type, { expires : 0.5 });
        },
        update: function(restore = false, layoutType = "", blog = "", before = "", type = ""){
            console.log("Updating " + this.blog);
            if (this.updateLocked) {
                console.log("Update locked");
                return;
            }
            $("#loader").show();
            //this.currentPage++;

            //this.before = before != "" ? before : this.slides.length != 0 ? this.layoutType == "likes" ? this.slides[this.slides.length-1].liked_timestamp : this.slides[this.slides.length-1].id : "";
            this.before = before != "" ? before : this.slides.length != 0 ? this.layoutType == "likes" ? this.slides[this.slides.length-1].liked_timestamp : this.last_id : "";
            console.log("Before " + this.before);
            $.ajax({
                dataType: "json",
                url: "./index.php",
                async: true,
                data: {action: layoutType != "" ? layoutType : this.layoutType,
                       blog:   blog != "" ? blog : this.blog,
                       //page:   this.currentPage,
                       before:   this.before,
                       tag:this.tag,
                       type:   type != "" ? type : this.type,
                       own: this.own},
                context: this,
                success: restore ? this.restore : this.response,
                error: this.error/*,
                complete: this.complete*/
            });
        },
        like: function(action){
            console.log(action == "like" ? "Liking" : "Unliking");
            $("#loader").show();
            $.ajax({
                dataType: "json",
                url: "./index.php",
                async: true,
                data: {action: action,
                       id:   this.slides[this.currentSlide].id,
                       reblog_key:   this.slides[this.currentSlide].reblog_key},
                context: this,
                success: this.response
            });
        },
        follow: function(action){
            $("#loader").show();
            $.ajax({
                dataType: "json",
                url: "./index.php",
                async: true,
                data: {action: "follow",
                       blog:   this.slides[this.currentSlide].blog_name},
                context: this,
                success: this.response
            });
        },
        restore:  function(data){
            this.slides = [];
            this.response(data);
            setMessage("Restored");
        },
        response: function(data){
            console.log("Response");
            switch (data.code) {
                case 200:
                    $(document).trigger('auth');
                    if (data.hasOwnProperty('posts')) {
                        console.log("Get " + data.posts.length + " posts");
                        console.log(data); //console.log(data.posts);
                        $("#loader").hide();
                        if (data.posts.length == 0) {
                            this.noMore = true;
                            setMessage("No more posts.");
                        }
                        this.slides = this.slides.concat(data.posts);
                        console.log("Total posts: " + this.slides.length);
                        this.updateLocked = false;
                        if (this.currentSlide == 0) {
                            this.display();
                        }
                    }
                    if (data.hasOwnProperty('last_id')) this.last_id = data.last_id;
                    break;
                case 404:
                    setMessage(data.msg);
                    $("#loader").hide();
                    if (data.hasOwnProperty('posts')) {
                        console.log("Get " + data.posts.length + " posts");
                        console.log(data.posts);
                        if (data.posts.length == 0) this.noMore = true;
                        this.slides = this.slides.concat(data.posts);
                        console.log("Total posts: " + this.slides.length);
                    }
                    this.updateLocked = false;
                    this.clearPostInfo();
                    $("#header, #footer").show();
                    break;
                case 511:
                    console.log(data);
                    $("#loader").hide();
                    setMessage(data.msg);
                    if (data.hasOwnProperty('auth_url')) {
                        setTimeout(function(){
                            window.location.href = data.auth_url;
                        },1500)
                    }
                    break;
                case 201:
                case 202:
                default:
                    console.log(data);
                    $("#loader").hide();
                    setMessage(data.msg);
                    break;
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            console.log(textStatus, errorThrown);
            setMessage("Update error!");
            this.updateLocked = false;
        },
        /*
        complete: function(jqXHR, textStatus) {
            console.log(textStatus);
            this.updateLocked = false;
        },
        */
        lock: function() {
            console.log("Locked.");
            $("#loader").show();
            this.locked = true;
        },
        unlock: function() {
            console.log("Unlocked.");
            if (!this.updateLocked) $("#loader").hide();
            this.locked = false;
        },
        checkHidden: function() {
            if ($('body').is(":visible")) {
                console.log("Was not hidden");
                this.wasHidden = false;
            } else {
                console.log("Was hidden");
                this.wasHidden = true;
            }
        },
        display: function(){
            console.log("Current slide: " + this.currentSlide + "/" + this.slides.length + " >" + this.slides.length/2);

            this.lock();
            if (this.slides.length == 0) {
                this.unlock();
                this.clearPostInfo(true);
                $("#header, #footer").show();
                return;
            }
            this.displayPostInfo();
            if (this.slides[this.currentSlide].type == "photo") {
                this.displayPhoto();
            } else {
                this.displayVideo();
            }

            if (this.currentSlide-1 < 0 ) {
                console.log("Setting cookie before: NONE");
                Cookies.set("before", "", { expires : 0.5 });
            } else {
                console.log("Setting cookie before: " + this.slides[this.currentSlide-1].id);
                Cookies.set("before", this.slides[this.currentSlide-1].id, { expires : 0.5 });
                console.log("Previous slide id : " + this.slides[this.currentSlide-1].id);
            }
            console.log("Current slide id : " + this.slides[this.currentSlide].id);
        },
        displayPostInfo: function() {
            $("#blog-name").html(this.slides[this.currentSlide].blog_name);

            if ( this.slides[this.currentSlide].rebloged_from != "") {
                $("#reblogged-from").html(this.slides[this.currentSlide].rebloged_from);
                $("#reblogged-from-icon").show();
            } else {
                $("#reblogged-from").empty();
                $("#reblogged-from-icon").hide();
            }

            if ( this.slides[this.currentSlide].source != "") {
                $("#source").html(this.slides[this.currentSlide].source);
                $("#source-icon").show();
            } else {
                $("#source").empty();
                $("#source-icon").hide();
            }

            $("#date").html(this.age(this.slides[this.currentSlide].timestamp));

            var tags = "";
            $.each(this.slides[this.currentSlide].tags, function(i, obj) {
                        tags += "<a href=\"#\" class=\"post-tag\">#" + obj + "</a> ";
                    });
            tags = "<p>" + tags + "</p>";

            $("#footer").html(this.slides[this.currentSlide].caption + tags);
        },
        clearPostInfo: function(partial = false) {
            if (!partial)
                $("#blog-name").empty();

            $("#reblogged-from").empty();
            $("#reblogged-from-icon").hide();

            $("#source").empty();
            $("#source-icon").hide();

            $("#date").empty();
            $("#footer").empty();
        },
        displayPhoto: function() {
            this.iframe = new Image();
            this.iframe.src = this.slides[this.currentSlide].src;
            var _this = this;
            this.iframe.onload = function() {
                $('#content').empty();
                $(this).appendTo('#content').attr('id',"photo").addClass("photo");
                _this.resize();
                _this.unlock();
            };
        },
        displayVideo: function() {
            switch (this.slides[this.currentSlide].video_type) {
              case "tumblr":
                if (!this.slides[this.currentSlide].player) {
                    $("#content").empty().append($("#error-icon").clone());
                    this.unlock();
                    return;
                }
                $('#content').html(this.slides[this.currentSlide].player);
                this.iframe = $('video').first();
                this.resize();

                /*
                //$(this.iframe).attr("controls", "controls");
                //$(this.iframe).prop("controls",true);
                $(this.iframe).addClass("video-p");
                */

                $(this.iframe).removeAttr("controls");
                $(this.iframe).prop("controls",false);

                $(this.iframe).prop("autoplay",true);

                $(this.iframe).removeAttr("muted");
                //$(this.iframe).prop("muted",false);
                //$(this.iframe).prop("muted",stealthMode && videoMuted);
                $(this.iframe).prop("muted",videoMuted);
                $(this.iframe).prop("preload","none");

                var _this = this;
                var img = new Image();
                img.src = $(this.iframe).attr('poster');
                img.onload = function(){
                    console.log("Poster loaded");
                    _this.unlock();
                };



                $(this.iframe).find('source').last().on('error', function(e) {
                    $("#content").empty().append($("#error-icon").clone());
                    _this.unlock();
                });
                $(this.iframe).on("play",function (e){
                    _this.unlock();
                    $("#header, #footer").hide();
                    /*
                    setTimeout(function(){
                        //$(_this.iframe).removeAttr("controls");
                        //$(_this.iframe).prop("controls",false);
                        $(_this.iframe).removeClass("video-p");
                    }, 2000);
                    */
                })
                .on("ended",function (e){
                    _this.next();
                    _this.display();
                })
                .on('loadstart', function (event) {
                    $("#loader").show();
                })
                .on('loadeddata', function (event) {
                    _this.iframe[0].play();
                })
                .on('seeked', function (event) {
                    console.log("Video seek finished");
                })
                /*
                .on('load', function (event) {
                    console.log("Video loading continues");
                })
                .on('seeked', function (event) {
                    console.log("Video seek finished");
                })
                */
                .on('seeking', function (event) {
                    $("#loader").show();
                })
                /*
                .on('progress', function (event) {
                    console.log("Progress");
                })
                */
                .on('waiting', function (event) {
                    $("#loader").show();
                })
                .on('canplay', function (event) {
                    $("#loader").hide();
                });
                break;
              case "vimeo":
                if (!this.slides[this.currentSlide].player) {
                    $("#content").empty().append($("#error-icon").clone());
                    this.unlock();
                    return;
                }
                $('#content').html(this.slides[this.currentSlide].player);
                this.iframe = $('iframe').first();
                this.resize();
                this.unlock();
                $("#controls").show().fadeTo( 1000, 0 );
              break;
              case "instagram":
                if (!this.slides[this.currentSlide].player) {
                    $("#content").empty().append($("#error-icon").clone());
                    this.unlock();
                    return;
                }
                $('#content').html(this.slides[this.currentSlide].player);
                this.unlock();
                if (typeof window.instgrm !== 'undefined')
                    window.instgrm.Embeds.process();
                setTimeout(function(){
                    console.log(window.instgrm);
                    currentLayout.iframe = $('iframe').first();
                    currentLayout.resize();
                    $(currentLayout.iframe).css("max-width","")
                },2000)
                $("#controls").show().fadeTo( 1000, 0 );
              break;
              default:
                console.log("Video type: " + this.slides[this.currentSlide].video_type);
                console.log(this.slides[this.currentSlide].player);
                $("#content").empty().append($("#error-icon").clone());
                this.unlock();
              break;
            }
        },
        show: function(whereTo) {
            if (!this.locked) {
                if (this.slides[this.currentSlide].type == "video" && typeof this.iframe[0] !== 'undefined') {
                    this.iframe[0].pause();
                    $(this.iframe).attr('src','');
                    $(this.iframe).find('source').last().attr('src','');
                    this.iframe[0].load();
                    }
                var status;
                if (whereTo > 0) {
                    status = this.prev();
                } else {
                    status = this.next();
                }
                if (status) {
                    $("#controls").hide().css({ opacity: 1 });;
                    this.display();
                    $("#header, #footer").hide();
                }
            } else {
                console.log("Still locked. Downloading. Wait.");
            }
        },
        next: function(){
            console.log("Next");
            if (this.currentSlide+1 > this.slides.length/2) {
                if (!this.noMore) this.update();
                this.updateLocked = true;
            }
            if (this.currentSlide < this.slides.length-1) {
                this.currentSlide++;
                return true;
            } else {
                return false;
            }
        },
        prev: function(){
            console.log("Prev");
            if (this.currentSlide > 0) {
                this.currentSlide--;
                return true;
            } else {
                return false;
            }
        },
        resize: function(){
            this.checkHidden();
            if (this.wasHidden) {
                return;
            }
            console.log("Resizing");
            var elmt = window, prop = "inner";
            if (!("innerWidth" in window)) {
                elmt = document.documentElement || document.body;
                prop = "client";
            }
            var /*ww = elmt[prop + "Width"],
                wh = elmt[prop + "Height"],*/
                ww = Math.min(document.documentElement.clientWidth,window.innerWidth||0),
                wh = Math.min(document.documentElement.clientHeight,window.innerHeight||0),
                iw = $(this.iframe).width(),
                ih = $(this.iframe).height(),
                rw = wh / ww,
                ri = ih / iw,
                newWidth,
                newHeight;
            //console.log("ww: " + ww);
            //console.log("wh: " + wh);
            //console.log("iw: " + iw);
            //console.log("ih: " + ih);
            //console.log("rw: " + rw);
            //console.log("ri: " + ri);
            if (rw < ri) {
               newWidth = wh / ri;
               newHeight = wh;
            } else {
                newWidth = ww;
                newHeight = ww * ri;
            }
            //console.log("newWidth: " + newWidth);
            //console.log("newHeight: " + newHeight);
            properties = {
                width: newWidth + "px",
                height: newHeight + "px",
                top: (wh - newHeight) / 2,
                left: (ww - newWidth) / 2
            };
            $(this.iframe).css(properties);
        },
        age: function(timestamp) {
            var elapsed = new Date() - new Date(timestamp*1000);

            if (elapsed < 60000) {return Math.round(elapsed/1000) + ' seconds ago';}
            else if (elapsed < 3600000) {return Math.round(elapsed/60000) + ' minutes ago';}
            else if (elapsed < 86400000 ) {return Math.round(elapsed/3600000) + ' hours ago';}
            else if (elapsed < 2592000000) {return 'approx. ' + Math.round(elapsed/86400000) + ' days ago';}
            else if (elapsed < 31536000000) {return 'approx. ' + Math.round(elapsed/2592000000) + ' months ago';}
            else {return 'approx. ' + Math.round(elapsed/31536000000) + ' years ago';}
        },
        seek: function(direction) {
            if (this.slides[this.currentSlide].type == "video" && typeof this.iframe[0] !== 'undefined' && !isNaN(this.iframe[0].duration)) {
                stepPosition = Math.round(this.iframe[0].duration * 0.05);
                if (stepPosition < 1) stepPosition = 1;
                var newPosition = this.iframe[0].currentTime + stepPosition*direction;
                if (newPosition < 0)
                    newPosition = 0;
                else
                    if (newPosition > this.iframe[0].duration)
                        newPosition = this.iframe[0].duration - 1;
                this.iframe[0].currentTime = newPosition;
                setMessage(formatTime(newPosition) + " / " + formatTime(this.iframe[0].duration) + " (" + ( direction>0 ? "+" : "-" ) + stepPosition + ")", "timer");
            }
        },
        muteToggle: function() {
            if (this.slides[this.currentSlide].type == "video" && typeof this.iframe[0] !== 'undefined' && !isNaN(this.iframe[0].duration)) {
                videoMuted = !videoMuted;
                this.iframe[0].muted = videoMuted;

                $("#content svg.volume-icon").remove();
                $("#content").append($("#" + (this.iframe[0].muted ? "mute-icon" : "unmute-icon")).clone());

                setTimeout(function() {
                    $("#content svg.volume-icon").remove();
                }, 800);
            }
        },
        test: function(){
            console.log("this.layoutType: " + this.layoutType);
            console.log("this.blog: " + this.blog);
            console.log("this.type: " + this.type);
            console.log("this.tag: " + this.tag);
            console.log("this.own: " + this.own);
            console.log("this.currentSlide: " + this.currentSlide);
            console.log("this.currentPage: " + this.currentPage);
            console.log("this.slides.length: " + this.slides.length);
            console.log("this.slides");
            console.log(this.slides);
        }
    };

    window.__igEmbedLoaded = function( loadedItem ) {
        console.log("??");
    };

    var messageTimer;
    function setMessage (text, id="") {
         if (id == ""){
            $("#messages").append($("<span> </span>").html(text));
            setTimeout(function() {
                $("#messages span").first().remove();
            }, 5000);
        } else {
            if(!$('#messages span#' + id).length)
                $("#messages").append($("<span id=" + id + "> </span>").html(text));
            else
                $("#messages span#" + id).html(text);

            if (messageTimer) {
                clearTimeout(messageTimer);
                messageTimer = 0;
            }
            messageTimer = setTimeout(function() {
                $("#messages span#" + id).first().remove();
                }, 1000);
        }
    }

    function formatTime (seconds) {
        var hh = Math.floor(seconds / 3600);
        var mm = Math.floor(seconds%3600 / 60);
        var ss = Math.round(seconds%3600 % 60);
        return (hh>0 ? (hh<10 ? "0" : "") + hh + ":" : "") + (mm<10 ? "0" : "") + mm + ":" + (ss<10 ? "0" : "") + ss;
    }

    layouts.push({
        __proto__: layout$
    });

    currentLayout = layouts[0];

    currentLayout.update();

    //console.log("Cookies before_id: " + Cookies.get("before"));

    $(document).one('auth',function (e){
        if (typeof Cookies.get("before") !== 'undefined') {
            console.log("Dashboard MAY be restored");
            if (confirm('Do you want to restore dash?')) {
                console.log("Restore");
                $('#content').empty();
                if (Cookies.get("layoutType") == "dash") {
                    console.log("Restore dash");
                    currentLayout.update(true, Cookies.get("layoutType"),Cookies.get("blog"),Cookies.get("before"),Cookies.get("type"));
                } else {
                    console.log("Restore " + Cookies.get("layoutType") + " " + Cookies.get("blog") + " " + Cookies.get("type"));
                    layouts.push({
                        __proto__: layout$,
                        layoutType: Cookies.get("layoutType"),
                        blog: Cookies.get("blog"),
                        type: Cookies.get("type")
                    });
                    currentLayout = layouts[layouts.length-1];
                    $("#type").val(currentLayout.type);
                    currentLayout.update(true, "","",Cookies.get("before"),"");
                    $("#back").show();
                    $("#header, #footer").hide();
                }
            } else {
                console.log("Don't restore");
            }
        } else {
            console.log("Dashboard MAY NOT be restored");
        }
        currentLayout.save();
    });

    $(window).resize(function (e){
        currentLayout.resize()
    });

    $("#content, #controls").on('click',function (e){

        if ($(currentLayout.iframe).is("video")) {
            if ($(currentLayout.iframe)[0].paused) {
                $(currentLayout.iframe)[0].play();
                $("#header, #footer").hide();
            } else {
                /*
                $(currentLayout.iframe).attr("controls","");
                */
                $(currentLayout.iframe)[0].pause();
                $("#header, #footer").show();
            }
        } else {
            $("#header, #footer").toggle();
        }
    });
    $("#blog-name, #reblogged-from, #source, #likes, #view-blog").on('click',function (e){
        console.log("Clicked on " + ( $(this).children().length == 0 ? "blog link " + $(this).html() : "button with id " + this.id) );
        switch(this.id) {
            case 'likes':
                $('#content').empty();
                layouts.push({
                    __proto__: layout$,
                    layoutType: "likes"
                });
            break;
            case 'view-blog':
                if ($('#view-blog-name').is(":visible")) {
                    $('#view-blog-name').hide();
                    if ($('#view-blog-name').val() != '') {
                        $('#content').empty();
                        layouts.push({
                            __proto__: layout$,
                            layoutType: "blog",
                            blog:$('#view-blog-name').val(),
                            own:($("#view-blog-name-own").is(':checked') ? "1" : "0")
                        });
                        $('#view-blog-name').val('');
                        $('#view-blog-name-own').attr('checked', false);
                    } else {
                        return;
                    }
                } else {
                    $('#view-blog-name').show();
                    return;
                }
            break;
            default:
                if ($(this).html() != "" ) {
                    $('#content').empty();
                    layouts.push({
                        __proto__: layout$,
                        layoutType: "blog",
                        blog:$(this).html(),
                        own:($("#view-blog-name-own").is(':checked') ? "1" : "0")
                    });
                    $('#view-blog-name-own').attr('checked', false);
                } else {
                    return;
                }
            break;
        }

        //console.log(layouts);
        currentLayout = layouts[layouts.length-1];
        $("#type").val(currentLayout.type);
        currentLayout.update();
        currentLayout.save();
        $("#back").show();
        if (layouts.length > 2) $("#home").show();
        $("#header, #footer").hide();
        //currentLayout.test();
    });
    $('#view-blog-name').on("keypress", function(e) {
        if (e.keyCode == 13)
            $('#view-blog').click();
    });
    $("#home, #back").on('click',function (e){
        if (layouts.length <= 1) return;
        switch(this.id) {
            case 'home':
                while (layouts.length > 1) {
                    layouts.pop();
                }
            break;
            case 'back':
                layouts.pop();
            break;
        }

        currentLayout = layouts[layouts.length-1];
        $("#type").val(currentLayout.type);
        currentLayout.save();
        currentLayout.display();
        if (layouts.length == 1) {
            $("#back").hide();
            $("#home").hide();
        }
    });
    $("#open-post").on('click',function (e){
        if (typeof currentLayout.slides[currentLayout.currentSlide].post_url !== 'undefined' && currentLayout.slides[currentLayout.currentSlide].post_url !== '')
            window.open(currentLayout.slides[currentLayout.currentSlide].post_url);
    });
    $("#like-post").on('click',function (e){
        if (currentLayout.layoutType != "likes") {
            currentLayout.like("like");
        } else {
            currentLayout.like("unlike");
        }
    });
    $("#follow").on('click',function (e){
       currentLayout.follow();
    });
    $("#following").on('click',function (e){
        if($.trim($("#followed-blogs-list").html())=='') {
            $("#loader").show();
            $.ajax({
                dataType: "json",
                url: "./index.php",
                async: true,
                data: {action: "followed"},
                success: function (data) {
                    $("#loader").hide();

                    data.followed_blogs.blogs.sort(function(a,b) {
                        return (a.updated < b.updated) ? 1 : ((b.updated < a.updated) ? -1 : 0);
                    });

                    $.each(data.followed_blogs.blogs, function(i, obj) {
                        $("#followed-blogs-list").append($("<li> </li>").append(
                            $('<a href="#" > </a>').addClass('blog-name').html(obj.name),
                            $('<span> </span>').addClass('updated').html(layout$.age(obj.updated)),
                        ));
                    });
                $("#followed-blogs").show();
                }
            });
        } else {
            $("#followed-blogs").show();
        }
    });
    $(document).on('click', '.blog-name', function (e){
        $("#followed-blogs").hide();
        $('#content').empty();
        layouts.push({
            __proto__: layout$,
            layoutType: "blog",
            blog:$(this).html()
        });
        currentLayout = layouts[layouts.length-1];
        $("#type").val(currentLayout.type);
        currentLayout.update();
        currentLayout.save();
        $("#back").show();
        if (layouts.length > 2) $("#home").show();
        $("#header, #footer").hide();
    });
    $(document).on('click', '.post-tag', function (e){
        $("#followed-blogs").hide();
        $('#content').empty();
        var tag = $(this).html().split("#",2)[1];
        var blog = currentLayout.slides[currentLayout.currentSlide].blog_name;
        if (currentLayout.layoutType == "blog") {
            console.log("Current layout is blog. Updating it.");
            currentLayout.tag = tag;
            currentLayout.currentSlide=0;
            currentLayout.currentPage=0;
            currentLayout.slides=[];
        } else {
            console.log("Current layout is dash or likes. Creating new layout and updating it.");
            console.log(blog + "#" + tag);
            layouts.push({
                __proto__: layout$,
                layoutType: "blog",
                blog:blog,
                tag:tag
            });
            currentLayout = layouts[layouts.length-1];
            $("#type").val(currentLayout.type);
            $("#back").show();
            if (layouts.length > 2) $("#home").show();
        }
        currentLayout.update();
        currentLayout.save();
        $("#header, #footer").hide();
    });
    $("#close").on('click',function (e){
        $("#followed-blogs").hide();
    });
    $("#fullscreen").on('click',function (e){
        var requestFullScreen = document.documentElement.requestFullscreen || document.documentElement.mozRequestFullScreen || document.documentElement.webkitRequestFullScreen || document.documentElement.msRequestFullscreen;
        var cancelFullScreen = document.exitFullscreen || document.mozCancelFullScreen || document.webkitExitFullscreen || document.msExitFullscreen;
        if(!document.fullscreenElement && !document.mozFullScreenElement && !document.webkitFullscreenElement && !document.msFullscreenElement)
            requestFullScreen.call(document.documentElement);
        else
            cancelFullScreen.call(document);
    });
    $("#type").change(function (e){
        $("#header, #footer").hide();
        $('#content').empty();
        currentLayout.type=this.value;
        currentLayout.currentSlide=0;
        currentLayout.currentPage=0;
        currentLayout.slides=[];
        currentLayout.update();
        currentLayout.save();
        $("#header, #footer").hide();
    });
    var timer;
    var hided = false;
    $(window).on("mousemove",function () {
        if (!hided) {
            //console.log("Cursor and controls not hided")
            if (timer) {
                clearTimeout(timer);
                timer = 0;
            }
        } else {
            //console.log("Cursor and controls hided")
            if ($(currentLayout.iframe).is("video")) {
                /*
                //$(currentLayout.iframe).prop("controls",true);
                //$(currentLayout.iframe).attr("controls","controls");
                $(currentLayout.iframe).addClass("video-p");
                */
            }
            $('html').css({cursor: ''});
            hided = false;
        }
        timer = setTimeout(function () {
            console.log("Hiding cursor and controls")
            if ($(currentLayout.iframe).is("video")) {
                /*
                //$(currentLayout.iframe).removeAttr("controls");
                //$(currentLayout.iframe).prop("controls",false);
                $(currentLayout.iframe).removeClass("video-p");
                */
            }
            $('html').css({cursor: 'none'});
            hided = true;
        }, 2000);
    });
    var stealthMode = !( navigator.userAgent.match(/Android/i)    ||
                         navigator.userAgent.match(/webOS/i)      ||
                         navigator.userAgent.match(/iPhone/i)     ||
                         navigator.userAgent.match(/iPad/i)       ||
                         navigator.userAgent.match(/iPod/i)       ||
                         navigator.userAgent.match(/BlackBerry/i) ||
                         navigator.userAgent.match(/Windows Phone/i));
    var videoMuted = true;
    $(window).on('mouseleave blur focusout', function (e) {
        e.preventDefault();
        if (stealthMode) {
            //console.log("Hiding body");
            $('body').hide();
        }
    });
    $(window).on('mouseenter mouseover', function (e) {
        e.preventDefault();
        if (stealthMode) {
            //console.log("Showing body");
            $('body').show();
            if (currentLayout.wasHidden) {
                currentLayout.resize();
            }
        }
    });
    // keybindings
    var enabled = true;
    $(document).on('keydown',function(e){
        var code = (e.keyCode ? e.keyCode : e.which);
        if (e.altKey && code == 81) { // Alt + 'q'
            enabled = !enabled;
            setMessage("Hot keys " + (enabled ? "enabled" : "disabled"));
            return;
        }
        if (enabled) switch (code){
            case 37:  // left
            case 65:  // 'a'
                currentLayout.show(1);
                break;
            case 39: // right
            case 68: // 'd'
                currentLayout.show(-1);
                break;
            case 81: // 'q'
                // home
                $("#home").click();
                break;
            case 87: // 'w'
                // back
                $("#back").click();
                break;
            case 69: // 'e'
                // blog
                $("#blog-name").click();
            case 83: // 's'
                // reblogged from
                $("#reblogged-from").click();
                break;
            case 88: // 'x'
                // source
                $("#source").click();
                break;
            case 70: // 'f'
                // follow
                $("#follow").click();
                break;
            case 78: // 'n'
                // photo
                $("#type").val("photo").trigger('change');
                break;
            case 66: // 'b'
                // both
                $("#type").val("all").trigger('change');
            case 86: // 'v'
                // video
                $("#type").val("video").trigger('change');
                break;
            case 84: // 't'
                // open post
                $("#open-post").click();
                break;
            case 76: // 'l'
                // like post
                $("#like-post").click();
                break;
            case 32: // space
                $("#content").click();
                break;
            case 67: // 'c'
                currentLayout.seek(1);
                break;
            case 90: // 'z'
                currentLayout.seek(-1);
                break;
            case 220: // '\'
                stealthMode = !stealthMode;
                videoMuted = stealthMode;
                setMessage("Stealth mode " + (stealthMode ? "enabled" : "disabled"));
                break;
            case 77: // 'm'
                currentLayout.muteToggle();
                break;
            case 73: // 'i'
                console.log(currentLayout.slides[currentLayout.currentSlide].id);
                break;
            default:
                break;
        }
    });
    // mouse wheel
    $("#content").on('mousewheel DOMMouseScroll',function (e){
        e.stopPropagation();
        currentLayout.show(parseInt(e.originalEvent.wheelDelta || - e.originalEvent.detail));
    });
    $("#prev, #next").on('click',function (e){
        e.stopPropagation();
        switch(this.id) {
            case 'prev':
                currentLayout.show(1);
            break;
            case 'next':
                currentLayout.show(-1);
            break;
        }
    });
    // touch
    var xDown,yDown,xUp,yUp = null;
    var xDiffPrev = 0;
    var touchOff = false;
    $("#content").bind('touchstart', function (ev) {
        ev.stopPropagation();
        if ( touchOff ) {return;}
        var e = ev.originalEvent;
        xDown = e.touches[0].clientX;
        yDown = e.touches[0].clientY;
    });
    $("#content").bind('touchmove', function (ev) {
        ev.stopPropagation();
        if ( touchOff ) {return;}
        var e = ev.originalEvent;
        if ( ! xDown || ! yDown ) {return;}
        xUp = e.touches[0].clientX;
        yUp = e.touches[0].clientY;
        var xDiff = xDown - xUp;
        var yDiff = yDown - yUp;
        var direction = 0;
        if ( Math.abs( xDiff ) > Math.abs( yDiff ) ) {
            if ( xDiff > 0 ) {
                direction = 1;
            } else if (xDiff < 0) {
                direction = -1;
            }
            if ( Math.abs(xDiff - xDiffPrev) > 30 ) {
                currentLayout.seek(-direction);
                xDiffPrev = xDiff;
            }
        }/* else {
            if ( yDiff > 25 ) {
                currentLayout.show(-1);
            } else if (yDiff < -25) {
                currentLayout.show(1);
            }
        }*/

    });
    $("#content").bind('touchend', function (ev) {
        ev.stopPropagation();
        if ( touchOff ) {return;}
        if ( typeof xUp == 'undefined' || ! xUp || ! yUp ) {return;}
        var xDiff = xDown - xUp;
        var yDiff = yDown - yUp;
        if ( Math.abs( xDiff ) > Math.abs( yDiff ) ) {
            /*if ( xDiff > 25 ) {
                //currentLayout.show(-1);
            } else if (xDiff < -25) {
                //currentLayout.show(1);
            }*/
        } else {
            if ( yDiff > 25 ) {
                currentLayout.show(-1);
            } else if (yDiff < -25) {
                currentLayout.show(1);
            }
        }
        xDown = null;
        yDown = null;
        xUp = null;
        yUp = null;
    });
  });
  </script>
  <style type="text/css">
    * {
        margin: 0;
        padding: 0;
        border: 0;
    }
    html,body {
        height: 100%;
        background:black;
        touch-action: none;
    }
    a {
        color:white;
    }
    #header {
        display:none;
        min-height:50px;
        max-height:50%;
        width: 100%;
        position: relative;
        top: 0;
        left:0;
        text-align:center;
    }
    #header:after {
        content: '';
        display: block;
        clear: both;
    }
    .header-container{
        display:inline-block;
        line-height: 50px;
    }
    #blogs {
        float: left;
        margin-left: 10px;
        text-align: left;
    }
    #date {
        text-align:left;
        font-style: italic;
        margin:0 auto !important;
        overflow: hidden;
    }
    #buttons{
        float: right;
        margin-right: 10px;
        text-align: right;
    }
    .button {
        text-decoration: none;
        margin-right: 1em;
    }
    .button:last-child {
        margin-right: initial;
    }
    .svg-icon {
        fill: currentColor;
        height: 3ex;
        width: 3ex;
        vertical-align: text-top;
    }
    #back, #home, .svg-path-icon {
        display:none;
    }
    #view-blog-name {
        /*display:none;*/
        border: 0.02px solid white;
        color: white;
        background-color: transparent;
        height: 1.5em;
        width: 10em;
        padding-left: 1ex;
    }
    #view-blog-name:focus { outline: none; }
    #view-blog-name::placeholder {
        color: rgba(255, 255, 255, .7);
    }
    select {
        border: 0.02px solid white;
        color: white;
        background-color: transparent;
        text-indent: 0.01px;
        height: 1.5em;
        padding-left: 1ex;
        margin-right: 1.3em;
    }
    select:focus { outline: none; }
    select option {
        color: white;
        background-color: black;
    }
    #loader {
        display:none;
        position: relative;
        /*
        position:fixed;
        top:10px;
        left:10px;
        */
        margin: 10px;
        border: 0.2em solid #f3f3f3;
        border-top: 0.2em solid #333333;
        border-radius: 50%;
        width: 1em;
        height: 1em;
        animation: spin 1s linear infinite;
        z-index: 10;
    }
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    #messages {
        max-height:10%;
        position: relative;
        text-align: center;
    }
    #messages span{
        display:block;
    }
    #content {
        height: 100%;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
    }
    .photo,video,iframe {
        display:block;
        position: relative;
    }
    .video-p::-webkit-media-controls-panel {
        display: flex !important;
        opacity: 1 !important;
    }
    #error-icon, #unmute-icon, #mute-icon {
        fill:grey;
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
    }
    #footer {
        display:none;
        min-height:0;
        max-height:40%;
        width: 100%;
        box-sizing: border-box;
        background: rgba(40, 40, 40, .5);
        position:fixed;
        bottom: 0;
        left:0;
        padding: 0 10px;
    }
    #footer a:not(.post-tag) {
        color:#8cbfd9;
    }
    .post-tag {
        text-decoration: none;
        color: #6eb34d;
    }
    #header, #messages, #footer, #followed-blogs {
        overflow-y: scroll;
        color:white;
        background: rgba(40, 40, 40, .5);
        text-shadow: 1px 1px 3px black, -1px -1px 3px black, -1px 1px 3px black, 1px -1px 3px black;
        z-index:1;
    }
    #header::-webkit-scrollbar, #messages::-webkit-scrollbar, #footer::-webkit-scrollbar, #followed-blogs::-webkit-scrollbar, #followed-blogs-list::-webkit-scrollbar {
        display: none;
    }
    #followed-blogs {
        display: none;
        position: absolute;
        top: 0;
        left: 0;
        right:0;
        margin: 0 auto;
        height: 100%;
        width: max-content;
        background: #000000ed;
        overflow-y: initial;
        text-align: right;
        z-index:2;
    }
    #followed-blogs-list {
        overflow: scroll;
        height: calc(100% - 3ex);
        text-align: left;
        list-style: none;
    }
    #followed-blogs-list li {
        margin-bottom: 1px;
        border-bottom: 1px solid #808080;
    }
    #followed-blogs-list li:last-child {
        border: none;
    }
    .blog-name {
        margin-right: 1ex;
        text-decoration: none;
    }
    .updated {
        text-align: right;
        display: block;
        /*float: right;*/
        font-style: italic;
        font-size: smaller;
        color: #BDBDBD;
    }
    #controls {
        display: none;
        position: absolute;
        bottom: 40%;
        left: 0;
        height: 20%;
        width: 100%;
        background: #0f0;
        opacity: 0.8;
        cursor: pointer;
    }
    .controls {
        height: 100%;
        position: absolute;
        top: 0;
        width: 20%;
        background: #f00;
        opacity: 0.8;
    }
    #next {
        right: 0;
    }
    #prev {
        left: 0;
    }
    @media screen and (max-width: 600px) {
        .header-container {
            line-height: initial;
        }
    }
  </style>
</head>
<body>
  <div id="header">
    <div id="blogs">
      <div id="blog" class="header-container">
      <a id="home" class="button" href="#" title="Home (Q)">
    <svg id="home-icon" class="svg-icon">
      <svg viewBox="0 0 100 100" version="1.1" x="0px" y="0px" width="100%" height="100%">
        <path d="M 82.916596,56.420924 51.203289,31.690592 c -0.707436,-0.551664 -1.699217,-0.551664 -2.406849,0 L 17.083328,56.420924 c -0.475538,0.370842 -0.75362,0.940118 -0.75362,1.543249 l 0,32.571429 c 0,1.080822 0.876321,1.956947 1.956947,1.956947 l 63.426614,0 c 1.080627,0 1.956948,-0.876125 1.956948,-1.956947 l 0,-32.571429 c 0,-0.603131 -0.278083,-1.172407 -0.753621,-1.543249" />
        <path d="M 99.197477,45.621688 51.208827,7.9256021 c -0.709785,-0.557535 -1.708023,-0.557535 -2.417808,0 L 0.80237008,45.621688 c -0.40802348,0.320548 -0.67201565,0.790215 -0.73405088,1.305479 -0.06183953,0.51546 0.08356165,1.034247 0.40410959,1.44227 l 6.81389441,8.674364 c 0.3863013,0.49139 0.9602739,0.748141 1.5403131,0.748141 0.4228963,0 0.8491194,-0.136595 1.2076317,-0.4182 L 50.000021,25.979613 89.965774,57.373742 c 0.40822,0.320744 0.927006,0.466145 1.442271,0.40411 0.515459,-0.06184 0.984931,-0.326027 1.305675,-0.734051 l 6.813894,-8.674364 c 0.667506,-0.849902 0.519766,-2.080039 -0.330137,-2.747749" />
      </svg>
    </svg>
    </a>
    <a id="back" class="button" href="#" title="Back (W)">
    <svg id="back-icon" class="svg-icon">
      <svg x="0px" y="0px" viewBox="0 0 30 30" width="100%" height="100%">
            <path d="M 7.9299556,14.646447 19.243664,3.3327381 c 0.19526,-0.1952605 0.511845,-0.1952619 0.707107,0 l 2.12132,2.1213203 c 0.195262,0.1952619 0.19526,0.5118463 0,0.7071068 L 13.233256,15 l 8.838835,8.838835 c 0.195262,0.195262 0.19526,0.511846 0,0.707107 l -2.12132,2.12132 c -0.195261,0.195261 -0.511845,0.195262 -0.707107,0 L 7.9299556,15.353554 c -0.1979899,-0.19799 -0.1979899,-0.509117 0,-0.707107 z"/>
      </svg>
    </svg>
    </a>
    <a id="blog-name" href="#"  title="Visit this blog (e)"></a>
      </div>
      <div id="reblog" class="header-container">
    <svg id="reblogged-from-icon" class="svg-icon svg-path-icon">
      <svg x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
          <polygon points="36.496,59.407 36.499,48.238 49.982,48.241 30.177,27.559 8.787,47.978 24.142,47.981    24.136,71.879 63.87,71.89 51.752,59.508  "/>
          <polygon points="75.856,52.018 75.863,28.12 36.129,28.109 48.247,40.491 63.505,40.592 63.5,51.761 50.017,51.757    69.822,72.441 91.213,52.021  "/>
      </svg>
    </svg>
    <a id="reblogged-from" href="#" title="Visit reblogged blog (S)"></a>
      </div>
      <div id="srcblog" class="header-container">
    <svg id="source-icon" class="svg-icon svg-path-icon">
      <svg x="0px" y="0px" viewBox="0 0 96 96" width="100%" height="100%">
        <path d="M28.4,35.2C24.7,39.5,22,45.6,22,53.3v16.2h17.7c2.9,0,5.2-2.3,5.2-5.2V46.4H31.9c0.7-2.7,1.9-4.9,3.4-6.7   c1.6-1.9,3.5-3.4,6.2-4.4c2-0.7,3.3-2.6,3.3-4.7v-3.9C36.6,27.9,32.1,30.8,28.4,35.2z"/>
        <path d="M70.7,35.2c2-0.7,3.3-2.6,3.3-4.7v-3.9c-8.3,1.4-12.8,4.2-16.5,8.6c-3.7,4.4-6.4,10.4-6.4,18.1v16.2h17.7   c2.9,0,5.2-2.3,5.2-5.2V46.4H61.1c0.7-2.7,1.9-4.9,3.4-6.7C66.1,37.7,68,36.2,70.7,35.2z"/>
      </svg>
    </svg>
    <a id="source" href="#" title="Visit source blog (X)"></a>
      </div>
    </div>
      <div id="date" class="header-container"></div>
      <div id="buttons" class="header-container">
      <select id="type">
        <option value="all">both (B)</option>
        <option value="photo">photo (N)</option>
        <option value="video">video (V)</option>
      </select>
      <input type="text" id="view-blog-name" placeholder="Blog name">
      <input type="checkbox" id="view-blog-name-own" title="Own posts">
      <a id="view-blog" class="button" href="#" title="Visit specific blog">
        <svg id="view-blog-likes-icon" class="svg-icon">
          <svg viewBox="0 0 100 100" x="0px" y="0px" width="100%" height="100%">
            <path d="M77.82,8.13H22.18A22.18,22.18,0,0,0,0,30.31V69.69A22.18,22.18,0,0,0,22.18,91.87H77.82A22.18,22.18,0,0,0,100,69.69V30.31A22.18,22.18,0,0,0,77.82,8.13ZM81.41,74a3.59,3.59,0,0,1-3.59,3.59H22.17A3.59,3.59,0,0,1,18.59,74V70a3.59,3.59,0,0,1,3.59-3.59H77.83A3.59,3.59,0,0,1,81.41,70v4Zm0-22a3.59,3.59,0,0,1-3.59,3.59H22.17A3.59,3.59,0,0,1,18.59,52V48a3.59,3.59,0,0,1,3.59-3.59H77.83A3.59,3.59,0,0,1,81.41,48v4Zm0-22.59A3.59,3.59,0,0,1,77.83,33H22.17a3.59,3.59,0,0,1-3.59-3.59v-4a3.59,3.59,0,0,1,3.59-3.59H77.83a3.59,3.59,0,0,1,3.59,3.59v4Z"/>
          </svg>
        </svg>
      </a>
      <a id="likes" class="button" href="#" title="Liked posts">
        <svg id="likes-icon" class="svg-icon">
          <svg viewBox="0 0 100 100" x="0px" y="0px"  width="100%" height="100%">
            <path d="M45.8,80.3c2.3,2.3,6.1,2.3,8.4,0C64.5,70,85.1,50.9,87.6,44.8c1-2.4,1.5-4.9,1.4-7.6c-0.1-9.2-6.9-17-16-18.9   c-9-1.8-15.7,3.5-20.5,9.3c-1.3,1.6-3.7,1.6-5,0C42.7,21.8,36,16.5,27,18.3c-9,1.8-15.8,9.7-16,18.9c0,2.7,0.5,5.3,1.4,7.6   C14.9,50.9,35.5,70,45.8,80.3z"/>
          </svg>
        </svg>
      </a>
      <a id="like-post" class="button" href="#" title="Like this post (L)">
        <svg id="like-post-icon" class="svg-icon">
          <svg x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
            <path d="M 30.267578,17.945312 C 29.213672,17.961328 28.125,18.075781 27,18.300781 c -9,1.8 -15.8,9.698438 -16,18.898438 0,2.7 0.500391,5.301562 1.400391,7.601562 2.5,6.1 23.10039,25.2 33.40039,35.5 2.3,2.3 5.485219,2.3 7.785219,0 l 0,-4.326172 0,-8.267609 -18.957,0 0,-21.375 18.957,0 0,-18.957 21.375,0 0,18.957 c 11.687468,0 0,0 11.687468,0 0.748416,-1.040852 0.657618,-0.81502 0.951141,-1.531219 1,-2.4 1.500391,-4.901562 1.400391,-7.601562 -0.1,-9.2 -6.9,-16.998438 -16,-18.898438 -9,-1.8 -15.7,3.498828 -20.5,9.298828 -1.3,1.6 -3.7,1.6 -5,0 -4.2,-5.075 -9.855078,-9.766406 -17.232422,-9.654297 z" />
            <path d="m 58.586125,32.374857 0,18.957416 -18.957421,0 0,11.375 18.957421,0 0,18.9588 11.375,0 0,-18.9588 18.95743,0 0,-11.375 -18.95743,0 0,-18.957416 -11.375,0 z" />
          </svg>
        </svg>
      </a>
      <a id="following" class="button" href="#" title="Followed blogs">
        <svg id="following-icon" class="svg-icon">
          <svg x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
            <path d="M1.25,33.75H17.5V17.5H1.25V33.75z M1.25,58.125H17.5v-16.25    H1.25V58.125z M1.25,82.5H17.5V66.25H1.25V82.5z M28.334,17.5v16.25H98.75V17.5H28.334z M28.334,58.125H98.75v-16.25H28.334    V58.125z M28.334,82.5H98.75V66.25H28.334V82.5z">
            </path>
          </svg>
        </svg>
      </a>
      <a id="follow" class="button" href="#" title="Follow this blog (F)">
        <svg id="follow-icon" class="svg-icon">
          <svg x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
            <path d="m 1.25,33.75 16.25,0 0,-16.25 -16.25,0 z m 0,24.375 16.25,0 0,-16.25 -16.25,0 z m 0,24.375 16.25,0 0,-16.25 -16.25,0 z m 27.084,-65 0,16.25 70.416,0 0,-16.25 z m 0,40.625 18.276169,0 0,-16.25 -18.276169,0 z m 0,24.375 18.276169,0 0,-16.25 -18.276169,0 z" />
            <path d="m 68.41757,35.208784 0,18.957416 -18.95742,0 0,11.375 18.95742,0 0,18.9588 11.375,0 0,-18.9588 18.95743,0 0,-11.375 -18.95743,0 0,-18.957416 -11.375,0 z" />
          </svg>
        </svg>
      </a>
      <a id="open-post" class="button" href="#" title="Open this post (T)">
        <svg id="open-post-icon" class="svg-icon">
          <svg x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
            <path d="m 65,17.999985 0,8.875 c -13.1574,3.3428 -31.9085,12.29 -38,31.12503 15.2216,-13.35813 36.7436,-11.90278 38,-11.84378 l 0,8.87498 24,-18.49998 z m -50,6 c -2.0943,2.1e-4 -3.9998,1.90566 -4,4 l 0,50.00003 c 2e-4,2.0943 1.9057,3.9998 4,4 l 52,0 c 2.0943,-2e-4 3.9998,-1.9057 4,-4 l 0,-22.5625 -3.5625,2.75 -4.4375,3.4375 0,12.375 -44,0 0,-42.00003 24.0938,0 c 5.8517,-3.74585 12.0628,-6.32105 17.625,-8 z" />
          </svg>
        </svg>
      </a>
      <a id="fullscreen" class="button" href="#" title="Toggle fullscreen">
        <svg id="fullscreen-icon" class="svg-icon">
          <svg x="0px" y="0px" viewBox="0 0 100 100"  width="100%" height="100%">
            <polygon style="" points="85.669,76.831 71.338,62.5 62.5,71.338 76.831,85.669 62.5,100 100,100 100,62.5 "/>
            <polygon style="" points="37.5,71.338 28.662,62.5 14.331,76.831 0,62.5 0,100 37.5,100 23.169,85.669 "/>
            <polygon style="" points="37.5,0 0,0 0,37.5 14.331,23.169 28.527,37.354 37.365,28.516 23.169,14.331 "/>
            <polygon style="" points="100,0 62.5,0 76.831,14.331 62.635,28.516 71.473,37.354 85.669,23.169 100,37.5 "/>
          </svg>
        </svg>
      </a>
      </div>
  </div>
  <div id="messages"></div>
  <div id="loader"></div>
  <div id="content">
    <!--<img class="photo" id="photo" src="https://78.media.tumblr.com/e571c5a59194a56d45230be599b97db4/tumblr_p5d0bdvDXG1vt4jtuo1_1280.jpg" />-->
  </div>
  <div id="followed-blogs">
    <a id="close" href="#" title="Close">
      <svg id="close-icon" class="svg-icon">
        <svg x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
          <path d="M88.8,77.5L60.6,49.3l28.2-28.2c1.2-1.2,1.2-3.1,0-4.2l-8.5-8.5L50,38.7L19.6,8.3l-8.5,8.5c-1.2,1.2-1.2,3.1,0,4.2  l28.2,28.2L11.2,77.5c-1.2,1.2-1.2,3.1,0,4.2l8.5,8.5L50,59.9l30.4,30.4l8.5-8.5C90,80.6,90,78.7,88.8,77.5z"/>
        </svg>
      </svg>
    </a>
    <ul id="followed-blogs-list"></ul>
  </div>
  <div id="footer"></div>

  <div id="controls">
    <div id="prev" class="controls"></div>
    <div id="next" class="controls"></div>
  </div>

<svg style="display:none" id="svg-icons">
  <svg id="error-icon">
    <svg viewBox="0 0 253 253" x="0px" y="0px" width="100%" height="100%">
      <polygon points="86,127 0,41 41,0 127,86 213,0 253,41 167,127 253,213 213,253 127,167 41,253 0,213 "/>
    </svg>
  </svg>
  <svg id="mute-icon" class="volume-icon">
    <svg x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
      <path d="M70.564,57.797c0.538-0.797,0.988-1.616,1.339-2.445  c0.374-0.885,0.663-1.809,0.855-2.76c0.18-0.892,0.275-1.861,0.275-2.902c0-1.04-0.096-2.009-0.275-2.901  c-0.192-0.951-0.481-1.875-0.855-2.76c-0.351-0.829-0.801-1.648-1.339-2.445c-0.517-0.769-1.116-1.493-1.785-2.162  c-1.332-1.332-2.895-2.4-4.608-3.125c-1.758-0.741-2.582-2.766-1.842-4.523c0.74-1.758,2.766-2.583,4.524-1.843  c2.59,1.096,4.905,2.663,6.829,4.587c0.966,0.965,1.847,2.035,2.626,3.191c0.748,1.109,1.407,2.327,1.961,3.639  c0.549,1.296,0.975,2.663,1.262,4.087c0.286,1.421,0.437,2.846,0.437,4.256c0,1.411-0.15,2.836-0.437,4.258  c-0.287,1.423-0.713,2.79-1.262,4.086c-0.554,1.312-1.213,2.529-1.961,3.638c-0.219,0.325-0.445,0.644-0.68,0.953  c-0.809,1.071-0.966,0.67-1.884-0.249c-1.096-1.096-1.619-1.619-2.714-2.714C70.203,58.833,69.931,58.74,70.564,57.797z"/>
      <path d="M80.397,67.533c0.736-0.845,1.417-1.73,2.04-2.653  c0.989-1.47,1.829-3.006,2.5-4.591c0.7-1.653,1.239-3.384,1.601-5.173c0.346-1.709,0.526-3.522,0.526-5.427  s-0.181-3.717-0.526-5.425c-0.361-1.789-0.9-3.52-1.601-5.173c-0.671-1.585-1.511-3.122-2.5-4.591  c-0.977-1.446-2.098-2.803-3.344-4.049c-1.246-1.247-2.603-2.368-4.049-3.343c-1.469-0.99-3.007-1.83-4.592-2.501  c-1.758-0.74-2.582-2.766-1.842-4.524s2.767-2.583,4.523-1.842c2.067,0.875,4.003,1.924,5.784,3.125  c1.836,1.238,3.536,2.64,5.079,4.182c1.542,1.543,2.944,3.244,4.182,5.08c1.202,1.78,2.25,3.716,3.124,5.783  c0.874,2.065,1.551,4.24,2.008,6.501C93.763,45.146,94,47.415,94,49.689c0,2.275-0.237,4.545-0.688,6.781  c-0.457,2.261-1.134,4.436-2.008,6.501c-0.874,2.066-1.923,4.001-3.124,5.782c-0.875,1.298-1.832,2.527-2.861,3.682  c-0.813,0.911-0.86,0.651-1.708-0.195c-1.101-1.102-1.9-1.9-3.001-3.001C79.741,68.371,79.604,68.443,80.397,67.533z"/>
      <path d="M20.096,32.725h-8.13C8.685,32.725,6,35.41,6,38.691v22.484  c0,3.28,2.685,5.966,5.966,5.966h13.441c6.906,5.195,13.81,10.393,20.713,15.591c3.148,2.368,6.793,1.988,6.793-2.12  c0-4.925,0-9.442,0-14.365c0-1.745-0.261-2.222-1.506-3.468c-9.727-9.727-19.453-19.454-29.18-29.181  C21.37,32.739,21.311,32.725,20.096,32.725z"/>
      <path d="M12.589,12.588L12.589,12.588c2.116-2.115,5.577-2.115,7.692,0  l67.13,67.131c2.116,2.115,2.116,5.576,0,7.691l0,0c-2.115,2.116-5.576,2.116-7.691,0l-67.131-67.13  C10.474,18.165,10.474,14.704,12.589,12.588z"/>
      <path d="M46.121,17.134c-2.872,2.163-5.744,4.325-8.617,6.487  c-1.585,1.193-0.805,1.707,0.599,3.11c4.504,4.506,9.009,9.011,13.514,13.516c1.004,1.016,1.297,0.896,1.297-0.191v-20.8  C52.914,15.147,49.269,14.766,46.121,17.134z"/>
    </svg>
  </svg>
  <svg id="unmute-icon" class="volume-icon">
    <svg x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
      <path d="M66.853,69.518c-1.759,0.74-3.783-0.085-4.524-1.843c-0.74-1.759,0.086-3.783,1.844-4.522  c0.828-0.353,1.647-0.803,2.445-1.34c0.769-0.518,1.491-1.117,2.162-1.785c0.668-0.67,1.27-1.395,1.785-2.162  c0.538-0.797,0.988-1.617,1.339-2.446c0.374-0.886,0.663-1.808,0.854-2.761c0.181-0.893,0.275-1.86,0.275-2.9  s-0.096-2.01-0.275-2.901c-0.191-0.952-0.48-1.875-0.854-2.76c-0.351-0.829-0.801-1.649-1.339-2.445  c-0.518-0.768-1.117-1.492-1.785-2.162c-1.332-1.331-2.895-2.399-4.607-3.125c-1.758-0.74-2.584-2.766-1.844-4.523  c0.741-1.759,2.767-2.583,4.524-1.843c2.59,1.097,4.905,2.664,6.83,4.588c0.966,0.966,1.846,2.034,2.625,3.19  c0.748,1.109,1.407,2.326,1.962,3.638c0.548,1.297,0.975,2.664,1.262,4.088c0.286,1.42,0.438,2.845,0.438,4.256  s-0.15,2.836-0.438,4.256c-0.287,1.426-0.714,2.791-1.262,4.087c-0.555,1.312-1.214,2.53-1.962,3.64  c-0.779,1.156-1.659,2.226-2.625,3.19c-0.966,0.966-2.036,1.847-3.19,2.627C69.383,68.303,68.164,68.962,66.853,69.518z"/>
      <path d="M73.136,81.207c-1.759,0.74-3.783-0.084-4.524-1.842c-0.74-1.758,0.085-3.783,1.844-4.524  c1.584-0.67,3.121-1.511,4.59-2.501c1.447-0.975,2.805-2.098,4.051-3.344s2.367-2.603,3.343-4.049c0.99-1.469,1.83-3.006,2.501-4.59  c0.699-1.654,1.239-3.386,1.602-5.175c0.345-1.708,0.525-3.521,0.525-5.426s-0.182-3.719-0.525-5.427  c-0.361-1.787-0.901-3.52-1.602-5.173c-0.671-1.584-1.511-3.122-2.501-4.591c-0.976-1.446-2.097-2.803-3.343-4.049  s-2.604-2.367-4.051-3.343c-1.469-0.99-3.006-1.83-4.59-2.501c-1.759-0.741-2.584-2.768-1.844-4.524  c0.741-1.758,2.768-2.583,4.524-1.842c2.067,0.874,4.003,1.923,5.784,3.124c1.836,1.238,3.535,2.639,5.078,4.182  s2.944,3.244,4.183,5.079c1.2,1.782,2.25,3.717,3.124,5.784c0.874,2.063,1.55,4.24,2.007,6.5C93.764,45.214,94,47.481,94,49.757  c0,2.274-0.236,4.544-0.688,6.78c-0.457,2.261-1.133,4.437-2.007,6.5c-0.874,2.066-1.924,4.002-3.124,5.783  c-1.237,1.836-2.64,3.537-4.183,5.08c-1.543,1.541-3.244,2.943-5.08,4.182C77.138,79.283,75.203,80.332,73.136,81.207z"/>
      <path d="M46.121,17.202c-6.903,5.197-13.808,10.396-20.712,15.592H11.966C8.686,32.794,6,35.479,6,38.759v22.483  c0,3.281,2.686,5.965,5.966,5.965h13.442c6.904,5.197,13.81,10.395,20.712,15.592c3.147,2.367,6.793,1.988,6.793-2.121  c0-20.451,0-40.903,0-61.354C52.914,15.214,49.269,14.833,46.121,17.202z"/>
    </svg>
  </svg>
</svg>
</body>
</html>
