vcl 4.0;

#sub vcl_init {
#    new b = directors.round_robin()
#    b.add_backend(node1);
#}

backend default {
  .host = "127.0.0.1";
  .port = "8080";
  .connect_timeout = 3s;
  .first_byte_timeout = 120s;
  .between_bytes_timeout = 120s;
  .probe = { 
      .timeout = 100 ms; 
      .interval = 1s; 
      .window = 10;
      .threshold = 8;
      .request =
            "GET / HTTP/1.1"
            "Host: 127.0.0.1"
            "Accept-Charset: utf-8"
            "Connection: close" ;
  }
}
backend backend_setup {
  .host = "127.0.0.1";
  .port = "8080";
  .connect_timeout = 10s;
  .first_byte_timeout = 600s;
  .between_bytes_timeout = 600s;
}
acl purge {
"localhost"; 
"127.0.0.1";
}

acl elb {
"10.0.0.0"/24;
}
#sub vcl_init {
#    new cluster1 = directors.hash();;
#    cluster1.add_backend( backend_local, 1.0);
#    new setup1 = directors.hash();;
#    setup1.add_backend( backend_setup, 1.0);
#}


sub vcl_recv
{
    #On issues with Pushdo Virus
    #if (req.method == "POST" && ( req.url == "/" ||  req.url ~ "ptrxcz" ) ) {
    #    return (synth(555, "Response: Pushdo Virus"));
    #}
    if (req.http.Cookie !~ "eZSESSID" ) {
        # User don't have session cookie => Set a hardcoded anonymous hash
        set req.http.X-User-Hash = "38015b703d82206ebc01d17a39c727e5";
    }
    if (req.url ~ "/server-status")
    {
        return (pass);
    }
    if (req.url ~ "^/ezsetup")
    {
        set req.backend_hint = backend_setup;
    }
    else
    {
        set req.backend_hint = default;
    }

    # normalize encoding fpr less cached versions
    if (req.http.Accept-Encoding) {
        if (req.url ~ "\.(jpg|png|gif|gz|tgz|bz2|tbz|mp3|ogg)$") {
            # No point in compressing these
            unset req.http.Accept-Encoding;
        } elsif (req.http.Accept-Encoding ~ "gzip") {
            set req.http.Accept-Encoding = "gzip";
        } elsif (req.http.Accept-Encoding ~ "deflate") {
            set req.http.Accept-Encoding = "deflate";
        } else {
            # unkown algorithm
            unset req.http.Accept-Encoding;
        }
    }
    # All static
    if (req.url ~ "^/var/")
    {
        unset req.http.Cookie;
        return (hash);
    }
	if (req.url ~ "/ezinfo/is_alive")
    {
        return (pass);  
    }
    if (req.restarts == 0) {
     if (req.http.x-forwarded-for && !client.ip ~ elb ) {
       set req.http.X-Forwarded-For = "" + client.ip  + ", " + req.http.X-Forwarded-For;
     } elseif( !client.ip ~ elb ) {
       set req.http.X-Forwarded-For = client.ip;
     }
    }
    if (req.method == "PURGE") {
        if (!client.ip ~ purge) {
            return (synth(405, "Not allowed."));
        }
        ban("req.url ~ " + req.url + " && req.http.host == " + req.http.host);
        return (synth(200, "Purged."));
    }
    if (req.method != "GET" &&
        req.method != "HEAD" &&
        req.method != "PUT" &&
        req.method != "POST" &&
        req.method != "TRACE" &&
        req.method != "OPTIONS" &&
        req.method != "DELETE")
    {
        /* Non-RFC2616 or CONNECT which is weird. */
        return (pass);
    }
    # unknown problems with .gz files and yum
    # [Errno 14] Downloaded more than max size for http://packages.xrow.com/redhat/6/repodata/primary.xml.gz                          
    if (req.url ~ "\.gz$") {
        return (pass);
    }
    if (req.http.Cookie && (req.http.Cookie ~ "eZSESSID" || req.http.Cookie ~ "PHPSESSID"))
    {
       return (pass);
    }
    if (req.method != "GET" && req.method != "HEAD") {
        /* We only deal with GET and HEAD by default */
        return (pass);
    }
    if (req.http.Authorization) {
        /* Not cacheable by default */
        return (pass);
    }
    # @TODO think about auth cookies from other applications
    #if (req.http.Cookie) {
    #    /* Not cacheable by default */
    #    return (pass);
    #}
    set req.http.Surrogate-Capability = "abc=ESI/1.0";
    return (hash);
}
sub vcl_hash {  
    hash_data(req.url); 
    if (req.http.host) { 
        hash_data(req.http.host); 
    } else { 
        hash_data(server.ip); 
    }
    #You can disable this if sslzones are off in eZ Publish
    if( req.http.X-Forwarded-Proto ) {   
        hash_data(req.http.X-Forwarded-Proto);   
    }
    return (lookup); 
}
sub vcl_backend_response {
    set beresp.http.X-Backend = beresp.backend.name;
    if (beresp.http.Surrogate-Control ~ "ESI/1.0")
    {
        unset beresp.http.Surrogate-Control;
        set beresp.do_esi = true;
    }
    if (beresp.http.X-Varnish-Control == "disabled" ) {
        unset beresp.http.x-varnish-control;
        set beresp.uncacheable = true;
        return (deliver);
    }
    if (beresp.status == 404) {
       set beresp.ttl = 60s;
    }
    if(beresp.status == 301 && !beresp.http.Set-Cookie){
        set beresp.ttl = 4h;
    }
    set beresp.grace = 2h;
    if ( beresp.http.etag )
    {
       unset beresp.http.etag;
    }
    if ( beresp.ttl < 1s ) {
        set beresp.uncacheable = true;
        return (deliver);  
    }
    if (beresp.http.Set-Cookie) {
        set beresp.uncacheable = true;
        return (deliver);
    }
    # we can remove expire when we have max age
    if ( beresp.http.Expires && beresp.http.Cache-Control ~ "max-age=") {
        unset beresp.http.Expires;
    }
    return (deliver);
}
sub vcl_deliver 
{
    if( resp.http.Content-Type && resp.http.Content-Type ~ "text/html" )
    {
        unset resp.http.Last-Modified;
        unset resp.http.Expires;
        set resp.http.Cache-Control = "no-cache, must-revalidate";
        set resp.http.Pragma = "no-cache";
    }
    # We do not want to expire all at once; Age > max-age
    set resp.http.Age = "0";
    set resp.http.X-Powered-By = "eZ Publish by xrow";
    unset resp.http.Via;
    if (obj.hits > 0)
    {
        set resp.http.X-Cache = server.hostname + ":" + resp.http.X-Backend + ":HIT:" + obj.hits;
		# ensure set-cookie is never present
		if ( resp.http.Set-Cookie )
        {
            unset resp.http.Set-Cookie;
        }
    } else
    {
        set resp.http.X-Cache = server.hostname + ":" + resp.http.X-Backend + ":MISS";
    }
    if ( resp.http.X-Backend ) 
    { 
       unset resp.http.X-Backend; 
    }
}
sub vcl_pipe {
    set bereq.http.connection = "close";
    return (pipe);
}
sub vcl_backend_error {       
#        set resp.http.Content-Type = "text/html; charset=utf-8";
#        set resp.http.Cache-Control = "no-cache";
#        set resp.http.Retry-After = "5";
        synthetic("Sorry an error occured.");
#<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
#<html xmlns="http://www.w3.org/1999/xhtml">
#<head>
#<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
#<title>" + obj.status + " Internal Server Error</title>
#<style type="text/css">
#<!--
#body {
#        background-color: #B3D8F2;
#        margin-left: 0px;
#        margin-top: 0px;
#        margin-right: 0px;
#        margin-bottom: 0px;
#        color: white;
#        font-family: Arial, Helvetica, sans-serif;
#}
#img { border: 0}
#h1 {
#    color: white;
#        font-weight: bold; font-family: "Courier New", Courier, monospace;
#}
#label {
#    display: block;
#}
#
#a:link,a:visited,a:hover,a:active {
#        color: #FFFFFF;
#}
#-->
#</style>
#</head>
#
#<body>
#<div style="margin-top:10em; background-color: #0055D4;">&nbsp;</div>
#<div style="margin:auto 0; margin-top:2em; background-color: #0055D4; vertical-align: middle;">
#<div style="float: right;margin-right:20%;margin:2em; ">
#  <form style="background-color: #0055D4;" name="form1" method="post" action="http://www.xrow.com/xrow/callback">
#    <fieldset>
#    <legend>Contact <a href="http://www.xrow.com">xrow GmbH</a></legend>
#    <label>Your Email Address:
#  <input type="text" name="Telefon" id="senderName" />
#    </label>
#    <script type="application/javascript">
#            document.write('<input type="hidden" name="senderName" value="Error at ' + document.location.href + '" />');
#    </script>
#  <label>Message:  </label>
#
#  <label>
#  <textarea name="Message" id="Message" cols="45" rows="5"></textarea>
#  </label>
#
#                    <input name="position" value="rhs" type="hidden">
#
#  <input type="submit" name="Callback" id="send" value="Submit" />
#
#    </fieldset>
#  </form>
#  </div>
#    <div style="float:left; padding-left: 10%;margin-top:4em;">
#<a href="http://www.xrow.com"><img 
#src="http://www.xrow.com/extension/xrow/design/xrow/images/xrow_logo_invertiert_150x10.jpg" alt="xrow GmbH" name="xrow GmbH"  
#id="asdasdasd" style="background-color: #FFFFFF" /></a>  </div>
#<div style="float:left;margin-left:2em;margin-top:3em;">
#<h1 class="style2">"} + obj.status + {" Error</h1>
#<p><i>Sorry</i>, we will be back in a short moment</p>
#  </div>
#<div style="clear:both"></div>
#</div>
#</body>
#</html>");
    return (deliver);
}
