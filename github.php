<?php
ignore_user_abort(true);
function syscall ($cmd, $cwd) {
  $descriptorspec = array(
    1 => array('pipe', 'w'), // stdout is a pipe that the child will write to
    2 => array('pipe', 'w') // stderr
  );
  $resource = proc_open($cmd, $descriptorspec, $pipes, $cwd);
  if (is_resource($resource)) {
    $output = stream_get_contents($pipes[2]);
    $output .= PHP_EOL;
    $output .= stream_get_contents($pipes[1]);
    $output .= PHP_EOL;
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($resource);
    return $output;
  }
}
function git_current_branch ($cwd) {
  $result = syscall('git branch', $cwd);
  if (preg_match('/\\* (.*)/', $result, $matches)) {
    return $matches[1];
  }
}
// make sure the request is coming from GitHub
// https://help.github.com/articles/what-ip-addresses-does-github-use-that-i-should-whitelist
/*
 $gh_ips = array('207.97.227.253', '50.57.128.197', '108.171.174.178');
 if (in_array($_SERVER['REMOTE_ADDR'], $gh_ips) === false) {
 header('Status: 403 Your IP is not on our list; bugger off', true, 403);
 mail('root', 'GitHub hook error: bad ip', $_SERVER['REMOTE_ADDR']);
 exit();
 }
 */
// cd ..
// $cwd = dirname(__DIR__);
// GitHub will hit us with POST (http://help.github.com/post-receive-hooks/)
if (!empty($_POST['payload'])) {
  $payload = json_decode($_POST['payload']);
  // which branch was committed?
  $branch = substr($payload->ref, strrpos($payload->ref, '/') + 1);
  // If your website directories have the same name as your repository this would work.
  $repository = $payload->repository->name;
  $cwd = '/var/www/'.$repository;
  // only pull if we are on the same branch
  if ($branch == git_current_branch($cwd)) {
    // pull from $branch
    $cmd = sprintf('git pull origin %s', $branch);
    $result = syscall($cmd, $cwd);
    $output = '';
    // append commits
    foreach ($payload->commits as $commit) {
      $output .= $commit->author->name.' a.k.a. '.$commit->author->username;
      $output .= PHP_EOL;
      foreach (array('added', 'modified', 'removed') as $action) {
        if (count($commit->{$action})) {
          $output .= sprintf('%s: %s; ', $action, implode(',', $commit->{$action}));
        }
      }
      $output .= PHP_EOL;
      $output .= sprintf('because: %s', $commit->message);
      $output .= PHP_EOL;
      $output .= $commit->url;
      $output .= PHP_EOL;
    }
    // append git result
    $output .= PHP_EOL;
    $output .= $result;
    // send us the output
    mail('root', 'GitHub hook `'.$cmd.'` result', $output);
    // if you use APC, especially if you use apc.stat=0, we should clear APC
    // if (apc_clear_cache('opcode') == false || apc_clear_cache('user') == false) {
    //   mail('root', 'Unable to apc_clear_cache', '');
    // }
  }
}
?>