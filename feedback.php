<?php if (!defined('PmWiki')) exit();
/**
  Feedback: Accept messages from visitors to PmWiki pages
  Written by (c) Petko Yotov 2023   www.pmwiki.org/petko

  This text is written for PmWiki; you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published
  by the Free Software Foundation; either version 3 of the License, or
  (at your option) any later version. See pmwiki.php for full details
  and lack of warranty.
*/
$RecipeInfo['Feedback']['Version'] = '20230313';


SDVA($Feedback, array(
  'authpost' => 'read',
  'authread' => 'edit',
  'authdelete' => 'edit',
  'authedit' => 'edit',
  'maxlen' => '1024',
  'form' => 'top', # top, bottom, none
  'order' => '-time', # newer on top
  'FormFmt' => '{$SiteGroup}.FeedbackForm',
  'replarr' => array(),
  'blockpatterns' => array(),
));

SDVA($HandleActions, array(
  'feedback' => 'HandleFeedback'
));


$FmtPV['$FeedbackCount'] = 'count(preg_grep("/^fdbk/", array_keys($page))';

$MarkupDirectiveFunctions['feedback'] = 'FmtFeedback';
function FmtFeedback($pagename, $directive, $args, $content = '') {
  global $Feedback, $MessagesFmt, $PCache, $Author, $InputValues;
  
  $fname = FmtPageName($Feedback['FormFmt'], $pagename);
  
  $out = $lastposted = "";
  
  if(@$_GET["addfeedback"] == "success") {
    $out .= "!!!! %block notoc postsuccessful id=msgfeedback% $[Post successful]\n";
    
    $allposted = @array_keys((array)@$_SESSION['Feedback'][$pagename]);
    @rsort($allposted);
    
    $lastposted = @$allposted[0];
  }
  elseif(isset($_GET["rmfeedback"])) {
    $cnt = intval($_GET["rmfeedback"]);
    if($cnt>=0) {
      
    }
    $fmt = $cnt>=0 
      ? XL("%d post(s) deleted.")
      : '$[No posts checked.]'
      ;
    $msg = sprintf($fmt, $cnt);
    
    $out .= "!!!! %block notoc id=msgfeedback% $msg\n";
  }
  
  $InputValues['author'] = strval(@$Author);
  
  
  $out .= "(:include $fname:)\n";#&gt;&gt;feedback&lt;&lt;\n
  
  $candelete = CondAuth($pagename, $Feedback['authdelete']);
  $canread = CondAuth($pagename, $Feedback['authread']);
  
  $out2 = "";
  /*
  if(!$canread) {
    xmp($_SESSION['Feedback']);
  }*/
  $page = $PCache[$pagename];
  if($page) {
    
    $keys = preg_grep('/^fdbk\\d+$/', array_keys($page));
    rsort($keys,  SORT_NATURAL);
    
    foreach($keys as $k) {
    
      if(!$canread) {
        if(!isset($_SESSION['Feedback'][$pagename][$k])) continue;
      }
    
      $text = trim($page[$k]);
      if(!$text) continue;
      
      $time = intval(substr($k,4));
      $stamp = Keep(FmtDateTimeZ($time));
      
      list($name, $content) = explode("\n", PHSC($text), 2);
      if(preg_match('/^\\[\\[~[-a-zA-Z0-9]+\\|[^\\]]+\\]\\]$/', $name)) {
        $stamp .= " $[by] $name";
      }
      else 
        $stamp .= " $[by] ".Keep($name);
      
      $content = Keep(nl2br($content));
      
      
      if($candelete) {
        $stamp .= " %rfloat%(:input checkbox stamp[] $time \"$[Delete post]\":)%%";
      }
      
      $is_last = $lastposted == $k? " lastposted" : '';
//       $out2 .= ":%list listfeedback%$stamp : $content\n";
      $out2 .= "(:div44 class='feedbackpost$is_last':)\n(:div45 class='feedbackpostheader':)\n"
        . "$stamp\n(:div45 class='feedbackposttext':)\n$content\n(:div44end:)\n";
    
    }
  }
  
  if($out2 && $candelete) {
    $out2 = "(:input form \"{*\$PageUrl}\" method=post:)(:input hidden n $pagename:)"
      ."(:input hidden action feedback:)\n$out2\n"
      ."%rfloat%(:input submit delfeedback \"$[Delete selected posts]\":)%%"
      ."[[&lt;&lt;]]\n(:input end:)\n";
  }
  
  return PRR("$out\n<:vspace>\n$out2");
  
}

function HandleFeedback($pagename) {
  global $Feedback, $MessagesFmt, $AuthorLink, $Now, $AuthId;
  
  
  if(@$_POST['postfeedback']) {
//     xmp($_POST, 1);

    $text = trim(strval(@$_POST['csum']));
    $terms = intval(@$_POST['terms']);
    if(!$text || (!$AuthId && !$terms)) {
      $MessagesFmt[] = '<div class="msgfeedback">$[Missing feedback or unchecked terms.]</div>';
      return HandleBrowse($pagename);
    }
    if(!$AuthId && function_exists('IsCaptcha') && !IsCaptcha()) {
      $MessagesFmt[] = '<div class="msgfeedback">$[Please type the text from the picture.]</div>';
      return HandleBrowse($pagename);
    }

    $auth = $Feedback['authpost'];
    $canread = CondAuth($pagename, $Feedback['authread']);

    $page = $new = RetrieveAuthPage($pagename, $auth, true);
    if(!$page) return Abort('$[No permissions]');
  
    $new["fdbk$Now"] = "$AuthorLink\n$text";
    
    $new['csum'] = $new["csum:$Now"] = XL("Posting feedback");
    
    UpdatePage($pagename, $page, $new);
    
    pm_session_start();
    @$_SESSION['Feedback'][$pagename]["fdbk$Now"] = 1;
    session_write_close();
    
    
    Redirect($pagename, '$PageUrl?addfeedback=success#msgfeedback');
  }
  elseif(@$_POST['delfeedback']) {
    if(!isset($_POST['stamp']) || !count($_POST['stamp'])) {
      Redirect($pagename, "$PageUrl?rmfeedback=-1#msgfeedback");
    }
  
    $auth = $Feedback['authdelete'];
    $page = $new = RetrieveAuthPage($pagename, $auth, true);
    if(!$page) return Abort('$[No permissions]');
    
    
    $cntdeleted = 0;
    foreach($_POST['stamp'] as $stamp) {
      $key = "fdbk".intval($stamp);
      if(isset($new[$key])) {
        unset($new[$key]);
        $cntdeleted++;
      }
    }
    
    
    if($cntdeleted) {
      $fmt = XL("Deleted %d feedback post(s)");
      $new['csum'] = $new["csum:$Now"] = sprintf($fmt, $cntdeleted);
      UpdatePage($pagename, $page, $new);
    }
    Redirect($pagename, "\$PageUrl?rmfeedback=$cntdeleted#msgfeedback");
  }
  else {
    $MessagesFmt[] = '<div class="msgfeedback">$[Under construction.]</div>';
    return HandleBrowse($pagename);
  }

}
