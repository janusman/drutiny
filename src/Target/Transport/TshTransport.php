<?php

namespace Drutiny\Target\Transport;

use DateTime;
use Drutiny\Helper\ProcessUtility;
use Drutiny\Target\Exception\InvalidTargetException;
use Drutiny\Target\Exception\TargetLoadingException;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * RemoteService over Teleport (TSH).
 */
class TshTransport extends SshTransport {

  const ERROR_AMBIGUOUS_HOST_MSG = 'ambiguous host could match multiple nodes';

  protected string $telesyncRegion = '';

  /**
   * {@inheritdoc}
   */
  public function send(Process $command, ?callable $processor = null)
  {
    try {
      return parent::send($command, $processor);
    }
    // Look for teleport issue where an ambiguous host was detected.
    catch (ProcessFailedException $e) {
      $err = $e->getProcess()->getErrorOutput();
      if (strpos($err, self::ERROR_AMBIGUOUS_HOST_MSG) === false) {
        throw $e;
      }
      $output = $e->getMessage();
      $host = preg_quote($this->sshConfig['Host']);

      // Choose the first node found.
      if (!preg_match("/$host ([0-9a-z\-]+) /", $output, $matches)) {
        throw $e;
      }
      $node = $matches[1];

      $this->logger->warning("Teleport found multiple nodes for {$this->sshConfig['Host']}. Using first found: $node.");

      // Set future calls to use the Node rather than the hostname to avoid
      // ambiguous errors for the remainder of the process.
      $this->sshConfig['Host'] = $node;

      // Retry.
      return parent::send($command, $processor);
    }
  }

  /**
   * Formulate an SSH command. E.g. ssh -o User=foo hostname.bar
   */
  protected function getRemoteCall($bin = 'ssh')
  {
    $args = ['tsh ' . $bin];
    $options = $this->sshConfig;
    if (!isset($this->sshConfig['Host'])) {
      throw new \InvalidArgumentException("Missing 'Host' option in SSH Config.");
    }

    // Host is not a command line support option and must be passed as an argument.
    $host = $this->sshConfig['Host'];
    unset($options['Host']);

    if (isset($options['User'])) {
      $host = $options['User'].'@'.$host;
      unset($options['User']);
    }

    // Support for telesync region.
    if (isset($options['telesync.region'])) {
      $this->telesyncRegion = $options['telesync.region'];
    }
    unset($options['telesync.region']);

    // If telesync is enabled, then specify an explicit proxy.
    if (!empty($this->telesyncRegion)) {
      $activeRegions = $this->getActiveTelesyncRegions();
      // This means telesync is connected to a different region and we'd have to change that.
      if (!empty($activeRegions) && !in_array($this->telesyncRegion, array_keys($activeRegions))) {
        throw new TargetLoadingException("Cannot access target on current telesync clusters: " . implode(', ', array_keys($activeRegions)) . ". You must connect to '{$this->telesyncRegion}' instead.");
      }
      $args[] = '--proxy=' . $this->telesyncRegion;
    }
    
    foreach ($options as $key => $value) {
      $args[] = '-o';
      $args[] = sprintf('%s=%s', $key, $value);
    }
    $args[] = $host;
    return implode(' ', $args);
  }

  /**
   * Get a list of active regions.
   */
  protected function getActiveTelesyncRegions():array
  {
    // Add telesyncRegion to the cache ID.
    return $this->localCommand->run(Process::fromShellCommandline("telesync status | egrep '(Cluster:)|(Valid until:)' # {$this->telesyncRegion}"), function (string $output, CacheItemInterface $cache):array {
      $clusters = [];
      $cluster = null;
      foreach (array_filter(array_map('trim', explode("\n", $output))) as $row) {
        list($key, $value) = explode(':', $row, 2);
        if ($key == 'Cluster') {
          $cluster = trim($value);
          continue;
        }
        if ($key == 'Valid until' && $cluster !== null) {
          $clusters[$cluster] = new DateTime(substr(trim($value), 0, strpos(trim($value), ' [')));
          $cluster = null;
        }
      }

      $now = new DateTime();

      $valid = array_filter($clusters, fn($c) => $c > $now);

      $expiry = empty($valid) ? new DateTime('+1 second') : min(max($valid), new DateTime('+60 seconds'));
      $cache->expiresAt($expiry);

      return $valid;
    });
  }
}
