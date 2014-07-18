<?php
/** Favicons Lib
 *  ------------
 *  @copyright SODAWARE License (See below)
 *  @brief Simple lib to try to get favicons from URLs.
 */

/* LICENSE
 * --------------------------------------------------------------------------------
 * "THE NO-ALCOHOL BEER-WARE LICENSE" (Revision 42):
 * Phyks (webmaster@phyks.me) wrote this file. As long as you retain this notice you
 * can do whatever you want with this stuff (and you can also do whatever you want
 * with this stuff without retaining it, but that's not cool...). If we meet some
 * day, and you think this stuff is worth it, you can buy me a <del>beer</del> soda
 * in return.
 *                                                                      Phyks
 * ---------------------------------------------------------------------------------
 */


/**
 * Try to get the favicon associated with some URLs, by parsing the header and
 * trying to get the file favicon.ico at the root of the server
 *
 * @param an array $urls of URLs
 * @return an array {'favicons', 'errors'}. `errors` is an array of URLs for which there could not be any fetched favicon. `favicons` is an array with URLs as keys and an array of favicon urls and sizes ({favicon_url, size}, associative array).
 */
function getFavicon($urls) {
    $favicons = array();
    $errors = array();

    // Convert array to the good format for curl downloader
    $curl_urls = array();
    foreach($urls as $url) {
        $curl_urls[] = array('url'=>$url);
    }

    $contents = curl_downloader($curl_urls);
    foreach($contents['status_codes'] as $url=>$status) {
        if($status != 200) {
            $errors[] = $url;
        }
    }

    foreach($contents['results'] as $url=>$content) {
        $content = substr($content, 0, strpos($content, '</head>')).'</html>'; // We don't need the full page, just the <head>

        $html = new DOMDocument();
        $html->strictErrorChecking = false;
        @$html->loadHTML($content);
        $xml = simplexml_import_dom($html);

        // Try to fetch the favicon URL from the <head> tag
        foreach($xml->head->children() as $head_tag) {
            if($head_tag->getName() != 'link') {
                continue;
            }
            $go_next_tag = false;
            foreach($head_tag->attributes() as $key=>$attribute) {
                if($go_next_tag || $key != 'rel') {
                    continue;
                }
                if(strstr((string) $attribute, 'icon')) {
                    if(isset($head_tag->attributes()['sizes'])) {
                        $sizes = (string)$head_tag->attributes()['sizes'];
                    }
                    else {
                        $sizes = '';
                    }
                    $favicons[$url] = array(
                        'favicon_url'=>(string) $head_tag->attributes()['href'],
                        'sizes'=>$sizes
                    );
                    $go_next_tag = true;
                }
            }
        }
    }

    // Add to errors the URLs without any favicons associated
    $favicons_keys = array_keys($favicons);
    foreach($contents['results'] as $url=>$content) {
        if(!in_array($url, $favicons_keys)) {
            $errors[] = $url;
        }
    }

    // Check for errorred feeds wether the favicon.ico file at the root exists
    $second_try = array();
    foreach ($errors as $url) {
        $parsed_url = parse_url(trim($url));
        $second_try_url = "";
        if(isset($parsed_url['scheme'])) {
            $second_try_url .= $parsed_url['scheme'];
        }
        if(isset($parsed_url['host'])) {
            $second_try_url .= $parsed_url['host'];
        }
        if(isset($parsed_url['port'])) {
            $second_try_url .= $parsed_url['port'];
        }
        if(isset($parsed_url['user'])) {
            $second_try_url .= $parsed_url['user'];
        }
        if(isset($parsed_url['pass'])) {
            $second_try_url .= $parsed_url['pass'];
        }
        $second_try[] = array(
            'input_url'=>$url,
            'url'=>$second_try_url . '/favicon.ico'
        );
    }
    $second_try_curl = curl_downloader($second_try, false);
    $errors = array();

    foreach($second_try as $tested_url) {
        $status_code = (int) $second_try_curl['status_codes'][$tested_url['url']];
        if ($status_code >= 200 && $status_code < 400) {
            $favicons[$tested_url['input_url']] = array(
                'favicon_url'=>$tested_url['url'],
                'sizes'=>''
            );
        }
        else {
            $errors[] = $tested_url['input_url'];
        }
    }


    return array('favicons'=>$favicons, 'errors'=>$errors);
}


/**
 * Downloads all the urls in the array $urls and returns an array with the results and the http status_codes.
 *
 * Mostly inspired by blogotext by timovn : https://github.com/timovn/blogotext/blob/master/inc/fich.php
 *
 * @todo If open_basedir or safe_mode, Curl will not follow redirections :
 * https://stackoverflow.com/questions/24687145/curlopt-followlocation-and-curl-multi-and-safe-mode
 *
 * @param an array $urls of associative arrays {'url', 'post'} for each URL. 'post' is a JSON array of data to send _via_ POST.
 * @return an array {'results', 'status_code'}, results being an array of the retrieved contents, indexed by URLs, and 'status_codes' being an array of status_code, indexed by URL.
 */
function curl_downloader($urls, $fetch_content=true) {
    $chunks = array_chunk($urls, 40, true); // Chunks of 40 urls because curl has problems with too big "multi" requests
    $results = array();
    $status_codes = array();

    if (ini_get('open_basedir') == '' && ini_get('safe_mode') === false) { // Disable followlocation option if this is activated, to avoid warnings
        $follow_redirect = true;
    }
    else {
        $follow_redirect = false;
    }

    foreach ($chunks as $chunk) {
        $multihandler = curl_multi_init();
        $handlers = array();
        $total_feed_chunk = count($chunk) + count($results);

        foreach ($chunk as $i=>$url_array) {
            $url = $url_array['url'];
            set_time_limit(20); // Reset max execution time
            $handlers[$i] = curl_init($url);
            curl_setopt_array($handlers[$i], array(
                CURLOPT_RETURNTRANSFER => TRUE,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => $follow_redirect,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_USERAGENT => $_SERVER['HTTP_USER_AGENT'], // Add a user agent to prevent problems with some feeds
                CURLOPT_HEADER => $fetch_content ? FALSE : TRUE,
                CURLOPT_NOBODY => $fetch_content ? FALSE : TRUE,
            ));
            if (!empty($url_array['post'])) {
                curl_setopt($handlers[$i], CURLOPT_POST, true);
                curl_setopt($handlers[$i], CURLOPT_POSTFIELDS, json_decode($url_array['post'], true));
            }

            curl_multi_add_handle($multihandler, $handlers[$i]);
        }

        do {
            curl_multi_exec($multihandler, $active);
            curl_multi_select($multihandler);
        } while ($active > 0);

        foreach ($chunk as $i=>$url_array) {
            $url = $url_array['url'];
            $results[$url] = curl_multi_getcontent($handlers[$i]);
            $status_codes[$url] = curl_getinfo($handlers[$i], CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multihandler, $handlers[$i]);
            curl_close($handlers[$i]);
        }
        curl_multi_close($multihandler);
    }

    return array('results'=>$results, 'status_codes'=>$status_codes);
}


