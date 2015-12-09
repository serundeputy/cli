<?php

namespace Terminus\Dispatcher;

use Terminus;
use Terminus\Exceptions\TerminusException;

/**
 * A leaf node in the command tree.
 */
class Subcommand extends CompositeCommand {

  private $alias;

  private $when_invoked;

  /**
   * Object constructor. Sets object properties
   *
   * @param [RootCommand] $parent       Parent command dispatcher object
   * @param [string]      $name         Name of command to run
   * @param [DocParser]   $docparser    DocParser object for analysis of docs
   * @param [Closure]     $when_invoked Indicates classes & methods to use
   * @return [CompositeCommand] $this
   */
  public function __construct($parent, $name, $docparser, $when_invoked) {
    parent::__construct($parent, $name, $docparser);
    $this->when_invoked = $when_invoked;
    $this->alias        = $docparser->getTag('alias');
    $this->synopsis     = $docparser->getSynopsis();
    if (!$this->synopsis && $this->longdesc) {
      $this->synopsis = self::extractSynopsis($this->longdesc);
    }
  }

  /**
   * Tells whether there can be subcommands of this object
   *
   * @return [boolean] Always false
   */
  public function canHaveSubcommands() {
    return false;
  }

  /**
   * Gets the synopsis of the command this object represents
   *
   * @return [string] $this->synopsis
   */
  public function getSynopsis() {
    return $this->synopsis;
  }

  /**
   * Gets the alias of the command this object represents
   *
   * @return [string] $this->alias
   */
  public function getAlias() {
    return $this->alias;
  }

  /**
   * Displays the usage parameters of the command this object represents
   *
   * @param [string] $prefix Prefix for this command's usage
   * @return [void]
   */
  public function showUsage($prefix = 'usage: ') {
    Terminus::line($this->getUsage($prefix));
  }

  /**
   * Gets the usage parameters of the command this object represents
   *
   * @param [string] $prefix Prefix to usage string
   * @return [string] $usage
   */
  public function getUsage($prefix) {
    $usage = sprintf(
      '%s%s %s',
      $prefix,
      implode(' ', getPath($this)),
      $this->getSynopsis()
    );
    return $usage;
  }

  /**
   * Displays the usage parameters of the command this object represents
   *
   * @param [array] $args       Array of command line non-params and non-flags
   * @param [array] $assoc_args Array of command line params and flags
   * @return [void]
   */
  public function invoke($args, $assoc_args) {
    $to_unset = $this->validateArgs($args, $assoc_args);
    foreach ($to_unset as $key) {
      unset($assoc_args[$key]);
    }
    $path = getPath($this->getParent());
    call_user_func(
      $this->when_invoked,
      $args,
      $assoc_args
    );
  }

  /**
   * Parses thhe synopsis into an array
   *
   * @param [string] $longdesc The synopsis to parse
   * @return [array] $synopsis
   */
  private static function extractSynopsis($longdesc) {
    preg_match_all('/(.+?)[\r\n]+:/', $longdesc, $matches);
    $synopsis = implode(' ', $matches[1]);
    return $synopsis;
  }

  /**
   * Parses the arguments for prompting in interactive mode
   *
   * @param [array] $args       Array of command line non-params and non-flags
   * @param [array] $assoc_args Array of command line params and flags
   * @return [array] $args_array Elements as follows:
   *         [array] Array of command line non-params and non-flags
   *         [array] Array of command line params and flags
   */
  private function promptArgs($args, $assoc_args) {
    $synopsis = $this->getSynopsis();

    if (!$synopsis) {
      return array($args, $assoc_args);
    }

    $spec = array_filter(
      Terminus\SynopsisParser::parse($synopsis),
      function($spec_arg) {
        $is_in_array = in_array(
          $spec_arg['type'],
          array('generic', 'positional', 'assoc', 'flag')
        );
        return $is_in_array;
      }
    );

    $spec = array_values($spec);

    // 'positional' arguments are positional (aka zero-indexed)
    // so $args needs to be reset before prompting for new arguments
    $args = array();
    foreach ($spec as $key => $spec_arg) {
      $current_prompt = ($key + 1) . '/' . count($spec) . ' ';
      $default        = false;
      if ($spec_arg['optional']) {
        $default = '';
      }

      // 'generic' permits arbitrary key=value (e.g. [--<field>=<value>])
      if ($spec_arg['type'] == 'generic') {
        list($key_token, $value_token) = explode('=', $spec_arg['token']);

        $repeat = false;
        do {
          if (!$repeat) {
            $key_prompt = $current_prompt . $key_token;
          } else {
            $key_prompt = str_repeat(" ", strlen($current_prompt)) . $key_token;
          }

          $key = Terminus::prompt($key_prompt, $default);
          if ($key === false) {
            return array($args, $assoc_args);
          }

          if ($key) {
            $key_prompt_count = strlen($key_prompt) - strlen($value_token) - 1;
            $value_prompt     =
              str_repeat(' ', $key_prompt_count) . '=' . $value_token;

            $value = Terminus::prompt($value_prompt, $default);
            if (false === $value) {
              return array($args, $assoc_args);
            }

            $assoc_args[$key] = $value;

            $repeat   = true;
            $required = false;
          } else {
            $repeat = false;
          }

        } while ($required || $repeat);

      } else {
        $prompt = $current_prompt . $spec_arg['token'];
        if ('flag' == $spec_arg['type']) {
          $prompt .= ' (Y/n)';
        }

        $response = Terminus::prompt($prompt, $default);
        if (false === $response) {
          return array($args, $assoc_args);
        }

        if ($response) {
          switch ($spec_arg['type']) {
            case 'positional':
              if ($spec_arg['repeating']) {
                $response = explode(' ', $response);
              } else {
                $response = array($response);
              }
              $args = array_merge($args, $response);
                break;
            case 'assoc':
              $assoc_args[$spec_arg['name']] = $response;
                break;
            case 'flag':
              if ($response == 'Y') {
                $assoc_args[$spec_arg['name']] = true;
              }
                break;
          }
        }
      }
    }
    $args_array = array($args, $assoc_args);
    return $args_array;
  }

  /**
   * Decides which arguments are invalid for this command
   *
   * @param [array] $args       Array of command line non-params and non-flags
   * @param [array] $assoc_args Array of command line params and flags
   * @return [array] A list of invalid $assoc_args keys to unset
   */
  private function validateArgs($args, $assoc_args) {
    $synopsis = $this->getSynopsis();
    if (!$synopsis) {
      return array();
    }

    $validator = new Terminus\SynopsisValidator($synopsis);

    $cmd_path = implode(' ', getPath($this));
    foreach ($validator->getUnknown() as $token) {
      Terminus::getLogger()->warning(
        'The `{cmd}` command has an invalid synopsis part: {token}',
        array('cmd' => $cmd_path, 'token' => $token)
      );
    }

    if (!$validator->enoughPositionals($args)) {
      $this->showUsage();
      exit(1);
    }
    if ($this->name != 'help') {
      $invalid = $validator->invalidPositionals($args);
      if ($invalid) {
        throw new TerminusException(
          'Invalid positional value: {invalid}',
          array('invalid' => $invalid)
        );
      }
    }

    $unknownPositionals = $validator->unknownPositionals($args);
    if (!empty($unknownPositionals)) {
      throw new TerminusException(
        'Too many positional arguments: {args}',
        array('args' => implode(' ', $unknownPositionals))
      );
    }
    list($errors, $to_unset) = $validator->validateAssoc(
      array_merge(Terminus::getConfig(), $assoc_args)
    );
    foreach ($validator->unknownAssoc($assoc_args) as $key) {
      $errors['fatal'][] = "unknown --$key parameter";
    }
    if (!empty($errors['fatal'])) {
      $out = 'Parameter errors:';
      foreach ($errors['fatal'] as $error) {
        $out .= "\n " . $error;
      }

      throw new TerminusException($out);
    }
    foreach ($errors['warning'] as $warning) {
      Terminus::getLogger()->warning($warning);
    }

    return $to_unset;
  }

}
