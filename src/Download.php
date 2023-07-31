<?php
namespace webrium\console;

class Download{


    public static function url($url,$save_path,&$progress=false)
    {
        $file_name = basename($url);
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_BINARYTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_URL, $url);

        if($progress){
            curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, function ($resource, $download_size, $downloaded, $upload_size, $uploaded) use($progress, $file_name)
            {
                if ($progress && $download_size > 0){
                    $progress(($downloaded / $download_size  * 100), $file_name);
                }
            });
        }


        curl_setopt($curl, CURLOPT_NOPROGRESS, false); // needed to make progress function work

        curl_setopt($curl, CURLOPT_USERAGENT, 'Component');


        $content = curl_exec($curl);
        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            echo "cURL Error #:" . $err;
        }

        $fp = fopen("$save_path/$file_name", "wb");
        fwrite($fp, $content);
        fclose($fp);

        return $http_status;
    }

    public static function error($output,$message='Download failed. Please check your connection',$die=true)
    {
      $output->writeln("\n");
      $output->writeln("<error>$message</error>");

      if($die){
        die;
      }
    }

}
