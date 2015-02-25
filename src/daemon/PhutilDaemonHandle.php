<?php

final class PhutilDaemonHandle {

  const EVENT_DID_LAUNCH    = 'daemon.didLaunch';
  const EVENT_DID_LOG       = 'daemon.didLogMessage';
  const EVENT_DID_HEARTBEAT = 'daemon.didHeartbeat';
  const EVENT_WILL_GRACEFUL = 'daemon.willGraceful';
  const EVENT_WILL_EXIT     = 'daemon.willExit';

  private $overseer;
  private $daemonClass;
  private $argv;
  private $pid;
  private $daemonID;
  private $deadline;
  private $heartbeat;
  private $stdoutBuffer;
  private $restartAt;
  private $silent;
  private $shouldRestart = true;
  private $shouldShutdown;
  private $future;
  private $traceMemory;

  public function __construct(
    PhutilDaemonOverseer $overseer,
    $daemon_class,
    array $argv,
    array $config) {

    $this->overseer = $overseer;
    $this->daemonClass = $daemon_class;
    $this->argv = $argv;
    $this->config = $config;
    $this->restartAt = time();

    $this->daemonID = $this->generateDaemonID();
    $this->dispatchEvent(
      self::EVENT_DID_LAUNCH,
      array(
        'argv' => $this->argv,
        'explicitArgv' => idx($this->config, 'argv'),
      ));
  }

  public function isRunning() {
    return (bool)$this->future;
  }

  public function isDone() {
    return (!$this->shouldRestart && !$this->isRunning());
  }

  public function getFuture() {
    return $this->future;
  }

  public function setSilent($silent) {
    $this->silent = $silent;
    return $this;
  }

  public function getSilent() {
    return $this->silent;
  }

  public function setTraceMemory($trace_memory) {
    $this->traceMemory = $trace_memory;
    return $this;
  }

  public function getTraceMemory() {
    return $this->traceMemory;
  }

  public function update() {
    $this->updateMemory();

    if (!$this->isRunning()) {
      if (!$this->shouldRestart) {
        return;
      }
      if (!$this->restartAt || (time() < $this->restartAt)) {
        return;
      }
      if ($this->shouldShutdown) {
        return;
      }
      $this->startDaemonProcess();
    }

    $future = $this->future;

    $result = null;
    if ($future->isReady()) {
      $result = $future->resolve();
    }

    list($stdout, $stderr) = $future->read();
    $future->discardBuffers();

    if (strlen($stdout)) {
      $this->didReadStdout($stdout);
    }

    $stderr = trim($stderr);
    if (strlen($stderr)) {
      $this->logMessage('STDE', $stderr);
    }

    if ($result !== null) {
      list($err) = $result;
      if ($err) {
        $this->logMessage('FAIL', pht('Process exited with error %s', $err));
      } else {
        $this->logMessage('DONE', pht('Process exited normally.'));
      }

      $this->future = null;

      if ($this->shouldShutdown) {
        $this->restartAt = null;
        $this->dispatchEvent(self::EVENT_WILL_EXIT);
      } else {
        $this->scheduleRestart();
      }
    }

    $this->updateHeartbeatEvent();
    $this->updateHangDetection();
  }

  private function updateHeartbeatEvent() {
    if ($this->heartbeat > time()) {
      return;
    }

    $this->heartbeat = time() + $this->getHeartbeatEventFrequency();
    $this->dispatchEvent(self::EVENT_DID_HEARTBEAT);
  }

  private function updateHangDetection() {
    if (!$this->isRunning()) {
      return;
    }

    if (time() > $this->deadline) {
      $this->logMessage('HANG', pht('Hang detected. Restarting process.'));
      $this->annihilateProcessGroup();
      $this->scheduleRestart();
    }
  }

  private function scheduleRestart() {
    $this->logMessage('WAIT', pht('Waiting to restart process.'));
    $this->restartAt = time() + self::getWaitBeforeRestart();
  }

  /**
   * Generate a unique ID for this daemon.
   *
   * @return string A unique daemon ID.
   */
  private function generateDaemonID() {
    return substr(getmypid().':'.Filesystem::readRandomCharacters(12), 0, 12);
  }

  public function getDaemonID() {
    return $this->daemonID;
  }

  public function getPID() {
    return $this->pid;
  }

  private function getCaptureBufferSize() {
    return 65535;
  }

  private function getRequiredHeartbeatFrequency() {
    return 86400;
  }

  public static function getWaitBeforeRestart() {
    return 5;
  }

  public static function getHeartbeatEventFrequency() {
    return 120;
  }

  private function getKillDelay() {
    return 3;
  }

  private function getDaemonCWD() {
    $root = dirname(phutil_get_library_root('phutil'));
    return $root.'/scripts/daemon/exec/';
  }

  private function newExecFuture() {
    $class = $this->daemonClass;
    $argv = $this->argv;
    $buffer_size = $this->getCaptureBufferSize();

    // NOTE: PHP implements proc_open() by running 'sh -c'. On most systems this
    // is bash, but on Ubuntu it's dash. When you proc_open() using bash, you
    // get one new process (the command you ran). When you proc_open() using
    // dash, you get two new processes: the command you ran and a parent
    // "dash -c" (or "sh -c") process. This means that the child process's PID
    // is actually the 'dash' PID, not the command's PID. To avoid this, use
    // 'exec' to replace the shell process with the real process; without this,
    // the child will call posix_getppid(), be given the pid of the 'sh -c'
    // process, and send it SIGUSR1 to keepalive which will terminate it
    // immediately. We also won't be able to do process group management because
    // the shell process won't properly posix_setsid() so the pgid of the child
    // won't be meaningful.

    return id(new ExecFuture('exec ./exec_daemon.php %s %Ls', $class, $argv))
      ->setCWD($this->getDaemonCWD())
      ->setStdoutSizeLimit($buffer_size)
      ->setStderrSizeLimit($buffer_size)
      ->write(json_encode($this->config));
  }

  /**
   * Dispatch an event to event listeners.
   *
   * @param  string Event type.
   * @param  dict   Event parameters.
   * @return void
   */
  private function dispatchEvent($type, array $params = array()) {
    $data = array(
      'id' => $this->daemonID,
      'daemonClass' => $this->daemonClass,
      'childPID' => $this->pid,
    ) + $params;

    $event = new PhutilEvent($type, $data);

    try {
      PhutilEventEngine::dispatchEvent($event);
    } catch (Exception $ex) {
      phlog($ex);
    }
  }

  private function annihilateProcessGroup() {
    $pid = $this->pid;
    $pgid = posix_getpgid($pid);
    if ($pid && $pgid) {

      // NOTE: On Ubuntu, 'kill' does not recognize the use of "--" to
      // explicitly delineate PID/PGIDs from signals. We don't actually need it,
      // so use the implicit "kill -TERM -pgid" form instead of the explicit
      // "kill -TERM -- -pgid" form.
      exec("kill -TERM -{$pgid}");
      sleep($this->getKillDelay());

      // On OSX, we'll get a permission error on stderr if the SIGTERM was
      // successful in ending the life of the process group, presumably because
      // all that's left is the daemon itself as a zombie waiting for us to
      // reap it. However, we still need to issue this command for process
      // groups that resist SIGTERM. Rather than trying to figure out if the
      // process group is still around or not, just SIGKILL unconditionally and
      // ignore any error which may be raised.
      exec("kill -KILL -{$pgid} 2>/dev/null");
      $this->pid = null;
    }
  }


  private function gracefulProcessGroup() {
    $pid = $this->pid;
    $pgid = posix_getpgid($pid);
    if ($pid && $pgid) {
      exec("kill -INT -{$pgid}");
    }
  }

  private function updateMemory() {
    if ($this->traceMemory) {
      $memuse = number_format(memory_get_usage() / 1024, 1);
      $this->logMessage('RAMS', 'Overseer Memory Usage: '.$memuse.' KB');
    }
  }

  private function startDaemonProcess() {
    $this->logMessage('INIT', pht('Starting process.'));

    $this->deadline = time() + $this->getRequiredHeartbeatFrequency();
    $this->heartbeat = time() + self::getHeartbeatEventFrequency();
    $this->stdoutBuffer = '';

    $this->future = $this->newExecFuture();
    $this->future->start();

    $this->pid = $this->future->getPID();
  }

  private function didReadStdout($data) {
    $this->stdoutBuffer .= $data;
    while (true) {
      $pos = strpos($this->stdoutBuffer, "\n");
      if ($pos === false) {
        break;
      }
      $message = substr($this->stdoutBuffer, 0, $pos);
      $this->stdoutBuffer = substr($this->stdoutBuffer, $pos + 1);

      $structure = @json_decode($message, true);
      if (!is_array($structure)) {
        $structure = array();
      }

      switch (idx($structure, 0)) {
        case PhutilDaemon::MESSAGETYPE_STDOUT:
          $this->logMessage('STDO', idx($structure, 1));
          break;
        case PhutilDaemon::MESSAGETYPE_HEARTBEAT:
          $this->deadline = time() + $this->getRequiredHeartbeatFrequency();
          break;
        case PhutilDaemon::MESSAGETYPE_BUSY:
          $this->overseer->didBeginWork($this);
          break;
        case PhutilDaemon::MESSAGETYPE_IDLE:
          $this->overseer->didBeginIdle($this);
          break;
        case PhutilDaemon::MESSAGETYPE_DOWN:
          // The daemon is exiting because it doesn't have enough work and it
          // is trying to scale the pool down. We should not restart it.
          $this->shouldRestart = false;
          $this->shouldShutdown = true;
          break;
        default:
          // If we can't parse this or it isn't a message we understand, just
          // emit the raw message.
          $this->logMessage('STDO', pht('<Malformed> %s', $message));
          break;
      }
    }
  }

  public function didReceiveNotifySignal($signo) {
    $pid = $this->pid;
    if ($pid) {
      posix_kill($pid, $signo);
    }
  }

  public function didReceiveGracefulSignal($signo) {
    $this->shouldShutdown = true;
    if (!$this->isRunning()) {
      // If we aren't running a daemon, emit this event now. Otherwise, we'll
      // emit it when the daemon exits.
      $this->dispatchEvent(self::EVENT_WILL_EXIT);
    }

    $signame = phutil_get_signal_name($signo);
    if ($signame) {
      $sigmsg = pht(
        'Graceful shutdown in response to signal %d (%s).',
        $signo,
        $signame);
    } else {
      $sigmsg = pht(
        'Graceful shutdown in response to signal %d.',
        $signo);
    }

    $this->logMessage('DONE', $sigmsg, $signo);
    $this->gracefulProcessGroup();
  }

  public function didReceiveTerminalSignal($signo) {
    $signame = phutil_get_signal_name($signo);
    if ($signame) {
      $sigmsg = "Shutting down in response to signal {$signo} ({$signame}).";
    } else {
      $sigmsg = "Shutting down in response to signal {$signo}.";
    }

    $this->logMessage('EXIT', $sigmsg, $signo);
    $this->annihilateProcessGroup();
    $this->dispatchEvent(self::EVENT_WILL_EXIT);
  }

  private function logMessage($type, $message, $context = null) {
    if (!$this->getSilent()) {
      echo date('Y-m-d g:i:s A').' ['.$type.'] '.$message."\n";
    }

    $this->dispatchEvent(
      self::EVENT_DID_LOG,
      array(
        'type' => $type,
        'message' => $message,
        'context' => $context,
      ));
  }

}