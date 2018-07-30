<?php
//TODO: Show following blogs on side panel with scroll

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
                        if (isset($_GET['before'])) {
                            $options['before'] = $_GET['before'];
                        }
                    break;
                }
                switch ($action) {
                    case "dash":
                        $result = $client->getDashboardPosts($options);
                        $posts = $result->posts;
                    break;
                    case "blog":
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
                }
                $response->posts = [];
                foreach ($posts as $post) {
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
                        default:
                            break;
                    }
                }
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
  <!--<link rel="icon" href="img/favicon.ico" type="image/x-icon">-->
  <!--<link rel="shortcut icon" href="img/favicon.ico" type="image/x-icon">-->
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
        currentSlide:0,
        //currentPage:0,
        before:"",
        slides: [],
        noMore: false,
        iframe: null,
        locked: false,
        wasHidden: false,
        updateLocked: false,
        // Methods
        save: function(){
            Cookies.set("layoutType", this.layoutType, { expires : 10 });
            Cookies.set("blog", this.blog, { expires : 10 });
            Cookies.set("type", this.type, { expires : 10 });
        },
        update: function(restore = false, layoutType = "", blog = "", before = "", type = ""){
            console.log("Updating " + this.blog);
            if (this.updateLocked) {
                console.log("Update locked");
                return;
            }
            $("#loader").show();
            //this.currentPage++;

            this.before = before != "" ? before : this.slides.length != 0 ? this.layoutType == "likes" ? this.slides[this.slides.length-1].liked_timestamp : this.slides[this.slides.length-1].id : "";
            console.log("Before " + this.before);
            $.ajax({
                dataType: "json",
                url: "./index.php",
                async: true,
                data: {action: layoutType != "" ? layoutType : this.layoutType,
                       blog:   blog != "" ? blog : this.blog,
                       //page:   this.currentPage,
                       before:   this.before,
                       type:   type != "" ? type : this.type},
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
                    if (data.hasOwnProperty('posts')) {
                        console.log("Get " + data.posts.length + " posts");
                        console.log(data.posts);
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
            $("#loader").hide();
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
                Cookies.set("before", "", { expires : 10 });
            } else {
                Cookies.set("before", this.slides[this.currentSlide-1].id, { expires : 10 });
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
            $("#footer").html(this.slides[this.currentSlide].caption);
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
                $(this.iframe).prop("muted",stealthMode);
                $(this.iframe).prop("preload","auto");

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
            if (this.currentSlide > this.slides.length/2) {
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
        test: function(){
            console.log("this.layoutType: " + this.layoutType);
            console.log("this.blog: " + this.blog);
            console.log("this.type: " + this.type);
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

    console.log("Cookies before_id: " + Cookies.get("before"));

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
                            blog:$('#view-blog-name').val()
                        });
                        $('#view-blog-name').val('');
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
                        blog:$(this).html()
                    });
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
        if (e.altKey && code == 81) {
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
                setMessage("Stealth mode " + (stealthMode ? "enabled" : "disabled"));
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
        display:none;
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
    #error-icon {
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
    #footer a {
        color:#8cbfd9;
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
</svg>
</body>
</html>
