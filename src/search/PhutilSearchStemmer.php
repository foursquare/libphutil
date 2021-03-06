<?php

final class PhutilSearchStemmer
  extends Phobject {

  public function stemToken($token) {
    $token = $this->normalizeToken($token);
    return $this->applyStemmer($token);
  }

  public function stemCorpus($corpus) {
    $tokens = preg_split('/[^a-zA-Z0-9\x7F-\xFF]+/', $corpus);

    $words = array();
    foreach ($tokens as $key => $token) {
      if (strlen($token) < 3) {
        continue;
      }

      $normal_word = $this->normalizeToken($token);
      $words[$normal_word] = $normal_word;
    }

    $stems = array();
    foreach ($words as $normal_word) {
      $stems[] = $this->applyStemmer($normal_word);
    }

    return implode(' ', $stems);
  }

  private function normalizeToken($token) {
    return phutil_utf8_strtolower($token);
  }

  /**
   * @phutil-external-symbol class Porter
   */
  private function applyStemmer($normalized_token) {
    static $loaded;

    if ($loaded === null) {
      $root = dirname(phutil_get_library_root('phutil'));
      require_once $root.'/externals/porter-stemmer/src/Porter.php';
      $loaded = true;
    }

    $stem = Porter::stem($normalized_token);

    // If the stem is too short, it won't be a candidate for indexing. These
    // tokens are also likely to be acronyms (like "DNS") rather than real
    // English words.
    if (strlen($stem) < 3) {
      return $normalized_token;
    }

    return $stem;
  }

}
