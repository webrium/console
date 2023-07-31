<?php
namespace webrium\console;

class Zip{

  public function extract($zip_path,$extract_to)
  {
    $zip = new \ZipArchive;
    if ($zip->open($zip_path) === TRUE) {
        $zip->extractTo($extract_to);
        $zip->close();
        return true;
    }
    else {
      return false;
    }
  }

}
