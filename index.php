<?php
//TODO: Show following blogs on side panel with scroll
//TODO: Follow action
//TODO: Like action
//TODO: Open post on new page
//TODO: When no more posts?
//TODO: If no videos?
//TODO: Show post caption and other messages with pop-up notifications
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
        if (empty($_SESSION['Tumblr_oauth_token']) || empty($_SESSION['Tumblr_oauth_token_secret'])) {
            header('Content-Type: text/html; charset=utf-8');
            // start the old gal up
            $callbackUrl = $_SERVER['REQUEST_SCHEME']."://".$_SERVER['SERVER_NAME'].dirname($_SERVER['SCRIPT_NAME']);
            $resp = $requestHandler->request('POST', 'oauth/request_token', array(
                    'oauth_callback' => $callbackUrl
                ));
            // Get the result
            $result = (string) $resp->body;
            parse_str($result, $keys);
            $_SESSION['tmp_oauth_token'] = $keys['oauth_token'];
            $_SESSION['tmp_oauth_token_secret'] = $keys['oauth_token_secret'];
            $url = 'https://www.tumblr.com/oauth/authorize?oauth_token=' . $keys['oauth_token'];
            echo '<a href="'.$url.'">Connect Tumblr</a>';
            exit;
        }
        $client = new Tumblr\API\Client(
            $consumerKey,
            $consumerSecret,
            $_SESSION['Tumblr_oauth_token'],
            $_SESSION['Tumblr_oauth_token_secret']
        );

    $clientInfo = $client->getUserInfo();

    if (isset($_GET) && count($_GET)) {
        header('Content-Type: application/json; charset=utf-8');
        $action = (!empty($_GET['action']) ? $_GET['action'] : "dash");
        $page = (!empty($_GET['page']) ? $_GET['page'] : 1);
        $options['limit'] = 20;
        $obj = new stdClass;
        switch ($action) {
            case "dash":
            case "blog":
            case "likes":
                $options['offset']=($page-1)*$options['limit'];
                $options['reblog_info'] = true;
                switch ($action) {
                    case "dash":
                    case "blog":
                        if (isset($_GET['type']) && ($_GET['type'] == "photo" || $_GET['type'] == "video")) {
                            $options['type'] = $_GET['type'];
                        }
                    break;
                }
                switch ($action) {
                    case "dash":
                        $response = $client->getDashboardPosts($options);
                        $posts = $response->posts;
                    break;
                    case "blog":
                        if (!empty($_GET['blog'])) {
                            $blog = $_GET['blog'];
                        } else {
                            exit;
                        }
                        $response = $client->getBlogPosts($blog, $options);
                        $posts = $response->posts;
                    break;
                    case "likes":
                        $likes = $clientInfo->user->likes;
                        $totalPages = intval($likes / $options['limit']) + ($likes % $options['limit'] > 0 ? 1 : 0);
                        if ($page <= $totalPages) {
                            $response = $client->getLikedPosts($options);
                            $posts = $response->liked_posts;
                        } else {
                            echo json_encode([] ,JSON_UNESCAPED_UNICODE);
                            exit;
                        }
                    break;
                }
                $res_array = [];
                foreach ($posts as $post) {
                    $obj->blog_name = $post->blog_name;
                    $obj->type = $post->type;
                    $obj->id = $post->id;
                    $obj->timestamp = $post->timestamp;
                    $obj->reblog_key = $post->reblog_key;
                    $obj->liked_timestamp = (isset($post->liked_timestamp) ? $post->liked_timestamp : "" );
                    $obj->rebloged_from = (isset($post->reblogged_from_name) ? $post->reblogged_from_name : "" );
                    $obj->source = (isset($post->reblogged_root_name) ? $post->reblogged_root_name : "" );
                    switch ($post->type) {
                        case "photo":
                            foreach ($post->photos as $photo) {
                                $obj->src = $photo->original_size->url;
                                $res_array[] = clone $obj;
                            }
                            break;
                        case "video":
                            $obj->video_type = $post->video_type;
                            $obj->html5_capable = (isset($post->html5_capable) ? $post->html5_capable : "" );
                            $obj->player = (isset($post->player) ? $post->player[count($post->player)-1]->embed_code : "" );
                            $obj->video_url = (isset($post->video_url) ? $post->video_url : "" );
                            $res_array[] = clone $obj;
                            break;
                        default:
                            break;
                    }
                }
                echo json_encode($res_array ,JSON_UNESCAPED_UNICODE);
                break;
            case "followed":
                $obj = new stdClass;
                $obj->total_blogs = $clientInfo->user->following;
                $obj->blogs = [];
                $totalPages = intval($obj->total_blogs / $options['limit']) + ($obj->total_blogs % $options['limit'] > 0 ? 1 : 0);
                for ($page = 1; $page<=$totalPages; $page++) {
                    $options['offset']=($page-1)*$options['limit'];
                    $followedBlogs = $client->getFollowedBlogs($options);
                    $obj->blogs = array_merge($obj->blogs,$followedBlogs->blogs);
                }
                echo json_encode($obj ,JSON_UNESCAPED_UNICODE);
                break;
            default:
                echo "Wrong request.";
                break;
        }
    } else {
?>
<!DOCTYPE html>
<html>
<head>
  <title></title>
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
  <!--<script type="text/javascript" src="index.js"></script>-->
  <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  <script type="text/javascript">
  $(document).ready(function(){

    var currentLayout;

    var layouts = [];

    var layout$ = {
        // Properties
        layoutType: "dash",
        blog:"",
        type:"all",
        currentSlide:0,
        currentPage:0,
        slides: [],
        iframe: null,
        locked: false,
        wasHidden: false,
        updateLocked: false,
        // Methods
        update: function(){
            console.log("Updating " + this.blog);
            if (this.updateLocked) {
                console.log("Update locked");
                return;
            }
            $("#loader").show();
            this.currentPage++;
            $.ajax({
                dataType: "json",
                url: "./index.php",
                async: true,
                data: {action: this.layoutType,
                       blog:   this.blog,
                       page:   this.currentPage,
                       type:   this.type},
                context: this,
                success: this.response
            });
        },
        response: function(data){
            console.log("response");
            console.log("Get " + data.length + " posts");
            console.log(data);
            $("#loader").hide();
            this.slides = this.slides.concat(data);
            console.log("Total posts: " + this.slides.length);
            this.updateLocked = false;
            if (this.currentSlide == 0) {
                this.display();
            }
        },
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
                console.log("this.wasHidden = false;");
                this.wasHidden = false;
            } else {
                console.log("this.wasHidden = true;");
                this.wasHidden = true;
            }
        },
        display: function(){
            console.log("Current slide: " + this.currentSlide + "/" + this.slides.length + " >" + this.slides.length/2);
            this.lock();
            this.displayPostInfo();
            if (this.slides[this.currentSlide].type == "photo") {
                this.displayPhoto();
            } else {
                this.displayVideo();
            }
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
            if (this.slides[this.currentSlide].video_type == "tumblr") {
                $('#content').html(this.slides[this.currentSlide].player);
                this.iframe = $('video').first();
                this.resize();
                $(this.iframe).prop("controls",true);
                $(this.iframe).prop("autoplay",true);
                $(this.iframe).prop("muted",false);
                $(this.iframe).prop("preload","auto");
                var _this = this;
                $(this.iframe).on("play",function (e){
                    _this.unlock();
                    $("#header").hide();
                    setTimeout(function(){
                        $(_this.iframe).prop("controls",false);
                    }, 2000);
                });
                $(this.iframe).on("ended",function (e){
                    _this.next();
                    _this.display();
                });
            } else {
                //console.log(this.slides[this.currentSlide].type);
                //console.log(this.slides[this.currentSlide].video_type);
                //console.log(this.slides[this.currentSlide].player);
                this.unlock();
            }
        },
        show: function(whereTo) {
            if (!this.locked) {
                var status;
                console.log(whereTo);
                if (whereTo > 0) {
                    status = this.prev();
                } else {
                    status = this.next();
                }
                if (status) {
                    this.display();
                    $("#header").hide();
                }
            } else {
                console.log("Still locked. Downloading. Wait.");
            }
        },
        next: function(){
            //console.log("next");
            if (this.currentSlide > this.slides.length/2) {
                this.update();
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
            //console.log("prev");
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
            console.log("this.resizing");
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
            else if (elapsed < 2592000000) {return 'approximately ' + Math.round(elapsed/86400000) + ' days ago';}
            else if (elapsed < 31536000000) {return 'approximately ' + Math.round(elapsed/2592000000) + ' months ago';}
            else {return 'approximately ' + Math.round(elapsed/31536000000) + ' years ago';}
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

    layouts.push({
        __proto__: layout$
    });

    currentLayout = layouts[0];

    currentLayout.update();

    $(window).resize(function (e){
        currentLayout.resize()
    });
    $(window).on('mousewheel DOMMouseScroll',function (e){
        currentLayout.show(parseInt(e.originalEvent.wheelDelta || - e.originalEvent.detail));
    });
    $("#content").on('click',function (e){

        if ($(currentLayout.iframe).is("video")) {
            if ($(currentLayout.iframe)[0].paused) {
                $(currentLayout.iframe)[0].play();
                $("#header").hide();
            } else {
                $(currentLayout.iframe).prop("controls",true);
                $(currentLayout.iframe)[0].pause();
                $("#header").show();
            }
        } else {
            $("#header").toggle();
        }
    });
    $("#blog-name, #reblogged-from, #source, #likes, #view-blog").on('click',function (e){
        //console.log("Clicked on " + $(this).html());
        //console.log("with id " + this.id);
        switch(this.id) {
            case 'likes':
                layouts.push({
                    __proto__: layout$,
                    layoutType: "likes"
                });
            break;
            case 'view-blog':
                if ($('#view-blog-name').is(":visible")) {
                    $('#view-blog-name').hide();
                    if ($('#view-blog-name').val() != '') {
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
                layouts.push({
                    __proto__: layout$,
                    layoutType: "blog",
                    blog:$(this).html()
                });
            break;
        }

        //console.log(layouts);
        currentLayout = layouts[layouts.length-1];
        $("#type").val(currentLayout.type);
        currentLayout.update();
        $("#back-icon").show();
        //currentLayout.test();
    });
    $('#view-blog-name').on("keypress", function(e) {
        if (e.keyCode == 13)
            $('#view-blog').click();
    });
    $("#back-icon").on('click',function (e){
        layouts.pop();
        currentLayout = layouts[layouts.length-1];
        $("#type").val(currentLayout.type);
        currentLayout.display();
        if (layouts.length == 1) $("#back-icon").hide();
    });
    $("#type").change(function (e){
        currentLayout.type=this.value;
        currentLayout.currentSlide=0;
        currentLayout.currentPage=0;
        currentLayout.slides=[];
        currentLayout.update();
    });
    var timer;
    var hided = false;
    $(window).mousemove(function () {
        if (!hided) {
            if (timer) {
                clearTimeout(timer);
                timer = 0;
            }
        } else {
            $('html').css({cursor: ''});
            hided = false;
        }
        timer = setTimeout(function () {
            $('html').css({cursor: 'none'});
            hided = true;
        }, 2000);
    });
    var stealthMode = true;
    $(window).on('mouseleave blur focusout', function (e) {
        e.preventDefault();
        if (stealthMode) {
            console.log("Hiding body");
            $('body').hide();
        }
    });
    $(window).on('mouseenter mouseover', function (e) {
        e.preventDefault();
        if (stealthMode) {
            console.log("Showing body");
            $('body').show();
            if (currentLayout.wasHidden) {
                currentLayout.resize();
            }
        }
    });
    //Touch
    $('body')
        .on('swiperight',function(){
            console.log("Swipe right");
            currentLayout.show(1);
        })
        .on('swipeleft',function(){
            console.log("Swipe left");
            currentLayout.show(-1);
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
    }
    #header {
      height:50px;
      width: 100%;
      background: rgba(40, 40, 40, .5);
      color:white;
      position:fixed;
      top: 0;
      left:0;
      z-index:100;
    }
    #header {
        padding-left: 25px;
    }
    .header-text {
        line-height: 50px;
        color: white;
    }
    .svg-icon {
        fill: currentColor;
        height: 3ex;
        width: 3ex;
        vertical-align: text-top;
    }
    .svg-reblog-icon {
        display:none;
    }
    #back-icon {
        cursor: pointer;
    }
    #date {
        display: inline-block;
        width: 100%;
        position: fixed;
        text-align: center;
        top: 0;
        left: 0;
        z-index: -1;
    }
    #buttons {
        position: fixed;
        top: 0;
        right: 0;
        padding-right: 50px;
    }
    #buttons a {
       text-decoration: none;
    }
    #view-blog-name {
        display:none;
        border: 0.02px solid white;
        color: white;
        background-color: transparent;
        height: 1.5em;
        padding-left: 1ex;
    }
    #view-blog-name:focus { outline: none; }
    select {
        border: 0.02px solid white;
        color: white;
        background-color: transparent;
        text-indent: 0.01px;
        height: 1.5em;
        padding-left: 1ex;
    }
    select:focus { outline: none; }

    select option {
        color: white;
        background-color: black;
    }
    #loader {
        display:none;
        position:fixed;
        top:60px;
        left:10px;
        border: 0.2em solid #f3f3f3;
        border-top: 0.2em solid #333333;
        border-radius: 50%;
        width: 1em;
        height: 1em;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    #content {
      height: 100%;
    }
    .photo {
      display:block;
      position: relative;
    }
    video {
      display:block;
      position: relative;
    }
  </style>
</head>
<body>
  <div id="header">
    <svg id="back-icon" class="svg-icon svg-reblog-icon">
      <svg x="0px" y="0px" viewBox="0 0 30 30" width="100%" height="100%">
        <g stroke="none" stroke-width="1" sketch:type="MSPage">
          <g sketch:type="MSArtboardGroup" transform="translate(-45.000000, -585.000000)">
            <path d="M54,607.5 L54,591.5 C54,591.223858 54.2238576,591 54.5,591 L57.5,591 C57.7761424,591 58,591.223858 58,591.5 L58,604 L70.5,604 C70.7761424,604 71,604.223858 71,604.5 L71,607.5 C71,607.776142 70.7761424,608 70.5,608 L54.5,608 C54.2199998,608 54,607.779999 54,607.5 Z" sketch:type="MSShapeGroup" transform="translate(62.500000, 599.500000) rotate(-315.000000) translate(-62.500000, -599.500000) "/>
          </g>
        </g>
      </svg>
    </svg>
    <a id="blog-name" class="header-text" href="#"></a>
    <svg id="reblogged-from-icon" class="svg-icon svg-reblog-icon">
      <svg x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
        <g>
          <polygon points="36.496,59.407 36.499,48.238 49.982,48.241 30.177,27.559 8.787,47.978 24.142,47.981    24.136,71.879 63.87,71.89 51.752,59.508  "/>
          <polygon points="75.856,52.018 75.863,28.12 36.129,28.109 48.247,40.491 63.505,40.592 63.5,51.761 50.017,51.757    69.822,72.441 91.213,52.021  "/>
        </g>
      </svg>
    </svg>
    <a id="reblogged-from" class="header-text" href="#"></a>
    <svg id="source-icon" class="svg-icon svg-reblog-icon">
      <svg x="0px" y="0px" viewBox="0 0 96 96" width="100%" height="100%">
        <g>
          <path d="M28.4,35.2C24.7,39.5,22,45.6,22,53.3v16.2h17.7c2.9,0,5.2-2.3,5.2-5.2V46.4H31.9c0.7-2.7,1.9-4.9,3.4-6.7   c1.6-1.9,3.5-3.4,6.2-4.4c2-0.7,3.3-2.6,3.3-4.7v-3.9C36.6,27.9,32.1,30.8,28.4,35.2z"/>
          <path d="M70.7,35.2c2-0.7,3.3-2.6,3.3-4.7v-3.9c-8.3,1.4-12.8,4.2-16.5,8.6c-3.7,4.4-6.4,10.4-6.4,18.1v16.2h17.7   c2.9,0,5.2-2.3,5.2-5.2V46.4H61.1c0.7-2.7,1.9-4.9,3.4-6.7C66.1,37.7,68,36.2,70.7,35.2z"/>
        </g>
      </svg>
    </svg>
    <a id="source" class="header-text" href="#"></a>
    <a id="reblogged-from" class="header-text" href="#"></a>
    <div id="buttons">
      <input type="text" id="view-blog-name" placeholder="Blog name">
      <a id="view-blog" class="header-text" href="#">
        <svg id="view-blog-likes-icon" class="svg-icon">
          <svg viewBox="0 0 100 100" x="0px" y="0px">
            <path d="M77.82,8.13H22.18A22.18,22.18,0,0,0,0,30.31V69.69A22.18,22.18,0,0,0,22.18,91.87H77.82A22.18,22.18,0,0,0,100,69.69V30.31A22.18,22.18,0,0,0,77.82,8.13ZM81.41,74a3.59,3.59,0,0,1-3.59,3.59H22.17A3.59,3.59,0,0,1,18.59,74V70a3.59,3.59,0,0,1,3.59-3.59H77.83A3.59,3.59,0,0,1,81.41,70v4Zm0-22a3.59,3.59,0,0,1-3.59,3.59H22.17A3.59,3.59,0,0,1,18.59,52V48a3.59,3.59,0,0,1,3.59-3.59H77.83A3.59,3.59,0,0,1,81.41,48v4Zm0-22.59A3.59,3.59,0,0,1,77.83,33H22.17a3.59,3.59,0,0,1-3.59-3.59v-4a3.59,3.59,0,0,1,3.59-3.59H77.83a3.59,3.59,0,0,1,3.59,3.59v4Z"/>
          </svg>
        </svg>
      </a>
      <select id="type">
        <option value="all">All </option>
        <option value="photo">Photo</option>
        <option value="video">Video</option>
      </select>
      <a id="likes" class="header-text" href="#">
        <svg id="likes-icon" class="svg-icon">
          <svg viewBox="0 0 100 100" x="0px" y="0px"  width="100%" height="100%">
            <g>
              <path d="M45.8,80.3c2.3,2.3,6.1,2.3,8.4,0C64.5,70,85.1,50.9,87.6,44.8c1-2.4,1.5-4.9,1.4-7.6c-0.1-9.2-6.9-17-16-18.9   c-9-1.8-15.7,3.5-20.5,9.3c-1.3,1.6-3.7,1.6-5,0C42.7,21.8,36,16.5,27,18.3c-9,1.8-15.8,9.7-16,18.9c0,2.7,0.5,5.3,1.4,7.6   C14.9,50.9,35.5,70,45.8,80.3z"/>
            </g>
          </svg>
        </svg>
      </a>
      <a id="following" class="header-text" href="#">
        <svg id="following-icon" class="svg-icon">
          <svg x="0px" y="0px" viewBox="0 0 100 100" >
            <g>
              <path d="M1.25,33.75H17.5V17.5H1.25V33.75z M1.25,58.125H17.5v-16.25    H1.25V58.125z M1.25,82.5H17.5V66.25H1.25V82.5z M28.334,17.5v16.25H98.75V17.5H28.334z M28.334,58.125H98.75v-16.25H28.334    V58.125z M28.334,82.5H98.75V66.25H28.334V82.5z">
              </path>
            </g>
          </svg>
        </svg>
      </a>
    </div>
  <span id="date" class="header-text"></span>
  </div>
  <div id="loader"></div>
  <div id="content">
    <!--<img class="photo" id="photo" src="https://78.media.tumblr.com/e571c5a59194a56d45230be599b97db4/tumblr_p5d0bdvDXG1vt4jtuo1_1280.jpg" />-->
  </div>
</body>
</html>
<?php
      }
?>
