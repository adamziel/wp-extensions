<?php
declare(strict_types=1);

/**
 * Arabic Snowball stemmer generated from upstream Snowball sources.
 *
 * Provenance:
 * - Source algorithm: snowballstem/snowball algorithms/arabic.sbl.
 * - Reference generator: Snowball 3.1.1 PHP output from upstream commit 571875488db025b82aaff0b510a3684df925daa3.
 * - Verified fixture data: snowballstem/snowball-data arabic voc.txt.gz and output.txt.gz, 9,196,214 line pairs.
 *
 * Copyright (c) 2001, Dr Martin Porter
 * Copyright (c) 2004,2005, Richard Boulton
 * Copyright (c) 2013, Yoshiki Shibukawa
 * Copyright (c) 2006-2025, Olly Betts
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 * 3. Neither the name of the Snowball project nor the names of its
 *    contributors may be used to endorse or promote products derived from this
 *    software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * See docs/snowball-compliance.md for the preserved source identity and
 * verification procedure.
 */
final class WP_FTS_ArabicSnowballStemmer extends WP_FTS_SnowballGeneratedStemmer
{
    public const VARIANT = 'snowball-arabic';
    public const SOURCE_IDENTITY = 'Snowball Arabic generated from algorithms/arabic.sbl by Snowball 3.1.1; upstream snowball commit 571875488db025b82aaff0b510a3684df925daa3; verified against snowball-data arabic compressed fixtures with 9,196,214 line pairs';

    public function source_identity(): string
    {
        return self::SOURCE_IDENTITY;
    }

    private const A_0 = [
        ["\u{640}", -1, 1],
        ["\u{64B}", -1, 1],
        ["\u{64C}", -1, 1],
        ["\u{64D}", -1, 1],
        ["\u{64E}", -1, 1],
        ["\u{64F}", -1, 1],
        ["\u{650}", -1, 1],
        ["\u{651}", -1, 1],
        ["\u{652}", -1, 1],
        ["\u{660}", -1, 2],
        ["\u{661}", -1, 3],
        ["\u{662}", -1, 4],
        ["\u{663}", -1, 5],
        ["\u{664}", -1, 6],
        ["\u{665}", -1, 7],
        ["\u{666}", -1, 8],
        ["\u{667}", -1, 9],
        ["\u{668}", -1, 10],
        ["\u{669}", -1, 11],
        ["\u{FE80}", -1, 12],
        ["\u{FE81}", -1, 16],
        ["\u{FE82}", -1, 16],
        ["\u{FE83}", -1, 13],
        ["\u{FE84}", -1, 13],
        ["\u{FE85}", -1, 17],
        ["\u{FE86}", -1, 17],
        ["\u{FE87}", -1, 14],
        ["\u{FE88}", -1, 14],
        ["\u{FE89}", -1, 15],
        ["\u{FE8A}", -1, 15],
        ["\u{FE8B}", -1, 15],
        ["\u{FE8C}", -1, 15],
        ["\u{FE8D}", -1, 18],
        ["\u{FE8E}", -1, 18],
        ["\u{FE8F}", -1, 19],
        ["\u{FE90}", -1, 19],
        ["\u{FE91}", -1, 19],
        ["\u{FE92}", -1, 19],
        ["\u{FE93}", -1, 20],
        ["\u{FE94}", -1, 20],
        ["\u{FE95}", -1, 21],
        ["\u{FE96}", -1, 21],
        ["\u{FE97}", -1, 21],
        ["\u{FE98}", -1, 21],
        ["\u{FE99}", -1, 22],
        ["\u{FE9A}", -1, 22],
        ["\u{FE9B}", -1, 22],
        ["\u{FE9C}", -1, 22],
        ["\u{FE9D}", -1, 23],
        ["\u{FE9E}", -1, 23],
        ["\u{FE9F}", -1, 23],
        ["\u{FEA0}", -1, 23],
        ["\u{FEA1}", -1, 24],
        ["\u{FEA2}", -1, 24],
        ["\u{FEA3}", -1, 24],
        ["\u{FEA4}", -1, 24],
        ["\u{FEA5}", -1, 25],
        ["\u{FEA6}", -1, 25],
        ["\u{FEA7}", -1, 25],
        ["\u{FEA8}", -1, 25],
        ["\u{FEA9}", -1, 26],
        ["\u{FEAA}", -1, 26],
        ["\u{FEAB}", -1, 27],
        ["\u{FEAC}", -1, 27],
        ["\u{FEAD}", -1, 28],
        ["\u{FEAE}", -1, 28],
        ["\u{FEAF}", -1, 29],
        ["\u{FEB0}", -1, 29],
        ["\u{FEB1}", -1, 30],
        ["\u{FEB2}", -1, 30],
        ["\u{FEB3}", -1, 30],
        ["\u{FEB4}", -1, 30],
        ["\u{FEB5}", -1, 31],
        ["\u{FEB6}", -1, 31],
        ["\u{FEB7}", -1, 31],
        ["\u{FEB8}", -1, 31],
        ["\u{FEB9}", -1, 32],
        ["\u{FEBA}", -1, 32],
        ["\u{FEBB}", -1, 32],
        ["\u{FEBC}", -1, 32],
        ["\u{FEBD}", -1, 33],
        ["\u{FEBE}", -1, 33],
        ["\u{FEBF}", -1, 33],
        ["\u{FEC0}", -1, 33],
        ["\u{FEC1}", -1, 34],
        ["\u{FEC2}", -1, 34],
        ["\u{FEC3}", -1, 34],
        ["\u{FEC4}", -1, 34],
        ["\u{FEC5}", -1, 35],
        ["\u{FEC6}", -1, 35],
        ["\u{FEC7}", -1, 35],
        ["\u{FEC8}", -1, 35],
        ["\u{FEC9}", -1, 36],
        ["\u{FECA}", -1, 36],
        ["\u{FECB}", -1, 36],
        ["\u{FECC}", -1, 36],
        ["\u{FECD}", -1, 37],
        ["\u{FECE}", -1, 37],
        ["\u{FECF}", -1, 37],
        ["\u{FED0}", -1, 37],
        ["\u{FED1}", -1, 38],
        ["\u{FED2}", -1, 38],
        ["\u{FED3}", -1, 38],
        ["\u{FED4}", -1, 38],
        ["\u{FED5}", -1, 39],
        ["\u{FED6}", -1, 39],
        ["\u{FED7}", -1, 39],
        ["\u{FED8}", -1, 39],
        ["\u{FED9}", -1, 40],
        ["\u{FEDA}", -1, 40],
        ["\u{FEDB}", -1, 40],
        ["\u{FEDC}", -1, 40],
        ["\u{FEDD}", -1, 41],
        ["\u{FEDE}", -1, 41],
        ["\u{FEDF}", -1, 41],
        ["\u{FEE0}", -1, 41],
        ["\u{FEE1}", -1, 42],
        ["\u{FEE2}", -1, 42],
        ["\u{FEE3}", -1, 42],
        ["\u{FEE4}", -1, 42],
        ["\u{FEE5}", -1, 43],
        ["\u{FEE6}", -1, 43],
        ["\u{FEE7}", -1, 43],
        ["\u{FEE8}", -1, 43],
        ["\u{FEE9}", -1, 44],
        ["\u{FEEA}", -1, 44],
        ["\u{FEEB}", -1, 44],
        ["\u{FEEC}", -1, 44],
        ["\u{FEED}", -1, 45],
        ["\u{FEEE}", -1, 45],
        ["\u{FEEF}", -1, 46],
        ["\u{FEF0}", -1, 46],
        ["\u{FEF1}", -1, 47],
        ["\u{FEF2}", -1, 47],
        ["\u{FEF3}", -1, 47],
        ["\u{FEF4}", -1, 47],
        ["\u{FEF5}", -1, 51],
        ["\u{FEF6}", -1, 51],
        ["\u{FEF7}", -1, 49],
        ["\u{FEF8}", -1, 49],
        ["\u{FEF9}", -1, 50],
        ["\u{FEFA}", -1, 50],
        ["\u{FEFB}", -1, 48],
        ["\u{FEFC}", -1, 48]
    ];

    private const AS_0 = ["", "0", "1", "2", "3", "4", "5", "6", "7", "8", "9", "\u{621}", "\u{623}", "\u{625}", "\u{626}", "\u{622}", "\u{624}", "\u{627}", "\u{628}", "\u{629}", "\u{62A}", "\u{62B}", "\u{62C}", "\u{62D}", "\u{62E}", "\u{62F}", "\u{630}", "\u{631}", "\u{632}", "\u{633}", "\u{634}", "\u{635}", "\u{636}", "\u{637}", "\u{638}", "\u{639}", "\u{63A}", "\u{641}", "\u{642}", "\u{643}", "\u{644}", "\u{645}", "\u{646}", "\u{647}", "\u{648}", "\u{649}", "\u{64A}", "\u{644}\u{627}", "\u{644}\u{623}", "\u{644}\u{625}", "\u{644}\u{622}"];

    private const A_1 = [
        ["\u{622}", -1, 1],
        ["\u{623}", -1, 1],
        ["\u{624}", -1, 1],
        ["\u{625}", -1, 1],
        ["\u{626}", -1, 1]
    ];

    private const A_2 = [
        ["\u{622}", -1, 1],
        ["\u{623}", -1, 1],
        ["\u{624}", -1, 2],
        ["\u{625}", -1, 1],
        ["\u{626}", -1, 3]
    ];

    private const AS_2 = ["\u{627}", "\u{648}", "\u{64A}"];

    private const A_3 = [
        ["\u{627}\u{644}", -1, 2],
        ["\u{628}\u{627}\u{644}", -1, 1],
        ["\u{643}\u{627}\u{644}", -1, 1],
        ["\u{644}\u{644}", -1, 2]
    ];

    private const A_4 = [
        ["\u{623}\u{622}", -1, 2],
        ["\u{623}\u{623}", -1, 1],
        ["\u{623}\u{624}", -1, 1],
        ["\u{623}\u{625}", -1, 4],
        ["\u{623}\u{627}", -1, 3]
    ];

    private const A_5 = [
        ["\u{641}", -1, 1],
        ["\u{648}", -1, 1]
    ];

    private const A_6 = [
        ["\u{627}\u{644}", -1, 2],
        ["\u{628}\u{627}\u{644}", -1, 1],
        ["\u{643}\u{627}\u{644}", -1, 1],
        ["\u{644}\u{644}", -1, 2]
    ];

    private const A_7 = [
        ["\u{628}", -1, 1],
        ["\u{628}\u{627}", 0, -1],
        ["\u{628}\u{628}", 0, 2],
        ["\u{643}\u{643}", -1, 3]
    ];

    private const A_8 = [
        ["\u{633}\u{623}", -1, 4],
        ["\u{633}\u{62A}", -1, 2],
        ["\u{633}\u{646}", -1, 3],
        ["\u{633}\u{64A}", -1, 1]
    ];

    private const A_9 = [
        ["\u{62A}\u{633}\u{62A}", -1, 1],
        ["\u{646}\u{633}\u{62A}", -1, 1],
        ["\u{64A}\u{633}\u{62A}", -1, 1]
    ];

    private const A_10 = [
        ["\u{643}", -1, 1],
        ["\u{643}\u{645}", -1, 2],
        ["\u{647}\u{645}", -1, 2],
        ["\u{647}\u{646}", -1, 2],
        ["\u{647}", -1, 1],
        ["\u{64A}", -1, 1],
        ["\u{643}\u{645}\u{627}", -1, 3],
        ["\u{647}\u{645}\u{627}", -1, 3],
        ["\u{646}\u{627}", -1, 2],
        ["\u{647}\u{627}", -1, 2]
    ];

    private const A_11 = [
        ["\u{648}", -1, 1],
        ["\u{64A}", -1, 1],
        ["\u{627}", -1, 1]
    ];

    private const A_12 = [
        ["\u{643}", -1, 1],
        ["\u{643}\u{645}", -1, 2],
        ["\u{647}\u{645}", -1, 2],
        ["\u{643}\u{646}", -1, 2],
        ["\u{647}\u{646}", -1, 2],
        ["\u{647}", -1, 1],
        ["\u{643}\u{645}\u{648}", -1, 3],
        ["\u{646}\u{64A}", -1, 2],
        ["\u{643}\u{645}\u{627}", -1, 3],
        ["\u{647}\u{645}\u{627}", -1, 3],
        ["\u{646}\u{627}", -1, 2],
        ["\u{647}\u{627}", -1, 2]
    ];

    private const A_13 = [
        ["\u{646}", -1, 1],
        ["\u{648}\u{646}", 0, 3],
        ["\u{64A}\u{646}", 0, 3],
        ["\u{627}\u{646}", 0, 3],
        ["\u{62A}\u{646}", 0, 2],
        ["\u{64A}", -1, 1],
        ["\u{627}", -1, 1],
        ["\u{62A}\u{645}\u{627}", 6, 4],
        ["\u{646}\u{627}", 6, 2],
        ["\u{62A}\u{627}", 6, 2],
        ["\u{62A}", -1, 1]
    ];

    private const A_14 = [
        ["\u{62A}\u{645}", -1, 1],
        ["\u{648}\u{627}", -1, 1]
    ];

    private const A_15 = [
        ["\u{648}", -1, 1],
        ["\u{62A}\u{645}\u{648}", 0, 2]
    ];

    private bool $B_is_defined = false;
    private bool $B_is_verb = false;
    private bool $B_is_noun = false;


    private function utf8_length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $chars = [];
        if (preg_match_all('/./us', $value, $chars) !== false) {
            return count($chars[0]);
        }

        return strlen($value);
    }


    protected function r_Normalize_pre(): bool
    {
        $v_1 = $this->cursor;
        while (true) {
            $v_2 = $this->cursor;
            $v_3 = $this->cursor;
            $this->bra = $this->cursor;
            $among_var = $this->find_among(self::A_0);
            if (0 === $among_var) {
                goto lab2;
            }
            $this->ket = $this->cursor;
            $this->slice_from(self::AS_0[$among_var - 1]);
            goto lab3;
        lab2:
            $this->cursor = $v_3;
            if ($this->cursor >= $this->limit) {
                goto lab1;
            }
            $this->inc_cursor();
        lab3:
            continue;
        lab1:
            $this->cursor = $v_2;
            break;
        }
    lab0:
        $this->cursor = $v_1;
        return true;
    }


    protected function r_Normalize_post(): bool
    {
        $v_1 = $this->cursor;
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_1) === 0) {
            goto lab0;
        }
        $this->bra = $this->cursor;
        $this->slice_from("\u{621}");
        $this->cursor = $this->limit_backward;
    lab0:
        $this->cursor = $v_1;
        $v_2 = $this->cursor;
        while (true) {
            $v_3 = $this->cursor;
            $v_4 = $this->cursor;
            $this->bra = $this->cursor;
            $among_var = $this->find_among(self::A_2);
            if (0 === $among_var) {
                goto lab3;
            }
            $this->ket = $this->cursor;
            $this->slice_from(self::AS_2[$among_var - 1]);
            goto lab4;
        lab3:
            $this->cursor = $v_4;
            if ($this->cursor >= $this->limit) {
                goto lab2;
            }
            $this->inc_cursor();
        lab4:
            continue;
        lab2:
            $this->cursor = $v_3;
            break;
        }
    lab1:
        $this->cursor = $v_2;
        return true;
    }


    protected function r_Checks1(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_3);
        if (0 === $among_var) {
            return false;
        }
        $this->ket = $this->cursor;
        switch ($among_var) {
            case 1:
                if ($this->utf8_length($this->current) <= 4) {
                    return false;
                }
                $this->B_is_noun = true;
                $this->B_is_verb = false;
                $this->B_is_defined = true;
                break;
            case 2:
                if ($this->utf8_length($this->current) <= 3) {
                    return false;
                }
                $this->B_is_noun = true;
                $this->B_is_verb = false;
                $this->B_is_defined = true;
                break;
        }
        return true;
    }


    protected function r_Prefix_Step1(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_4);
        if (0 === $among_var) {
            return false;
        }
        $this->ket = $this->cursor;
        switch ($among_var) {
            case 1:
                if ($this->utf8_length($this->current) <= 3) {
                    return false;
                }
                $this->slice_from("\u{623}");
                break;
            case 2:
                if ($this->utf8_length($this->current) <= 3) {
                    return false;
                }
                $this->slice_from("\u{622}");
                break;
            case 3:
                if ($this->utf8_length($this->current) <= 3) {
                    return false;
                }
                $this->slice_from("\u{627}");
                break;
            case 4:
                if ($this->utf8_length($this->current) <= 3) {
                    return false;
                }
                $this->slice_from("\u{625}");
                break;
        }
        return true;
    }


    protected function r_Prefix_Step2(): bool
    {
        $this->bra = $this->cursor;
        if ($this->find_among(self::A_5) === 0) {
            return false;
        }
        $this->ket = $this->cursor;
        if ($this->utf8_length($this->current) <= 3) {
            return false;
        }
        if (!($this->eq_s("\u{627}"))) {
            goto lab0;
        }
        return false;
    lab0:
        $this->slice_del();
        return true;
    }


    protected function r_Prefix_Step3a_Noun(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_6);
        if (0 === $among_var) {
            return false;
        }
        $this->ket = $this->cursor;
        switch ($among_var) {
            case 1:
                if ($this->utf8_length($this->current) <= 5) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if ($this->utf8_length($this->current) <= 4) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_Prefix_Step3b_Noun(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_7);
        if (0 === $among_var) {
            return false;
        }
        $this->ket = $this->cursor;
        switch ($among_var) {
            case 1:
                if ($this->utf8_length($this->current) <= 3) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if ($this->utf8_length($this->current) <= 3) {
                    return false;
                }
                $this->slice_from("\u{628}");
                break;
            case 3:
                if ($this->utf8_length($this->current) <= 3) {
                    return false;
                }
                $this->slice_from("\u{643}");
                break;
        }
        return true;
    }


    protected function r_Prefix_Step3_Verb(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_8);
        if (0 === $among_var) {
            return false;
        }
        $this->ket = $this->cursor;
        switch ($among_var) {
            case 1:
                if ($this->utf8_length($this->current) <= 4) {
                    return false;
                }
                $this->slice_from("\u{64A}");
                break;
            case 2:
                if ($this->utf8_length($this->current) <= 4) {
                    return false;
                }
                $this->slice_from("\u{62A}");
                break;
            case 3:
                if ($this->utf8_length($this->current) <= 4) {
                    return false;
                }
                $this->slice_from("\u{646}");
                break;
            case 4:
                if ($this->utf8_length($this->current) <= 4) {
                    return false;
                }
                $this->slice_from("\u{623}");
                break;
        }
        return true;
    }


    protected function r_Prefix_Step4_Verb(): bool
    {
        $this->bra = $this->cursor;
        if ($this->find_among(self::A_9) === 0) {
            return false;
        }
        $this->ket = $this->cursor;
        if ($this->utf8_length($this->current) <= 4) {
            return false;
        }
        $this->B_is_verb = true;
        $this->B_is_noun = false;
        $this->slice_from("\u{627}\u{633}\u{62A}");
        return true;
    }


    protected function r_Suffix_Noun_Step1a(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_10);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if ($this->utf8_length($this->current) < 4) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if ($this->utf8_length($this->current) < 5) {
                    return false;
                }
                $this->slice_del();
                break;
            case 3:
                if ($this->utf8_length($this->current) < 6) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_Suffix_Noun_Step1b(): bool
    {
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{646}"))) {
            return false;
        }
        $this->bra = $this->cursor;
        if ($this->utf8_length($this->current) <= 5) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_Suffix_Noun_Step2a(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_11) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        if ($this->utf8_length($this->current) <= 4) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_Suffix_Noun_Step2b(): bool
    {
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{627}\u{62A}"))) {
            return false;
        }
        $this->bra = $this->cursor;
        if ($this->utf8_length($this->current) < 5) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_Suffix_Noun_Step2c1(): bool
    {
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{62A}"))) {
            return false;
        }
        $this->bra = $this->cursor;
        if ($this->utf8_length($this->current) < 4) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_Suffix_Noun_Step2c2(): bool
    {
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{629}"))) {
            return false;
        }
        $this->bra = $this->cursor;
        if ($this->utf8_length($this->current) < 4) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_Suffix_Noun_Step3(): bool
    {
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{64A}"))) {
            return false;
        }
        $this->bra = $this->cursor;
        if ($this->utf8_length($this->current) < 3) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_Suffix_Verb_Step1(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_12);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if ($this->utf8_length($this->current) < 4) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if ($this->utf8_length($this->current) < 5) {
                    return false;
                }
                $this->slice_del();
                break;
            case 3:
                if ($this->utf8_length($this->current) < 6) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_Suffix_Verb_Step2a(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_13);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if ($this->utf8_length($this->current) < 4) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if ($this->utf8_length($this->current) < 5) {
                    return false;
                }
                $this->slice_del();
                break;
            case 3:
                if ($this->utf8_length($this->current) <= 5) {
                    return false;
                }
                $this->slice_del();
                break;
            case 4:
                if ($this->utf8_length($this->current) < 6) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_Suffix_Verb_Step2b(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_14) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        if ($this->utf8_length($this->current) < 5) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_Suffix_Verb_Step2c(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_15);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if ($this->utf8_length($this->current) < 4) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if ($this->utf8_length($this->current) < 6) {
                    return false;
                }
                $this->slice_del();
                break;
        }
        return true;
    }


    protected function r_Suffix_All_alef_maqsura(): bool
    {
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("\u{649}"))) {
            return false;
        }
        $this->bra = $this->cursor;
        $this->slice_from("\u{64A}");
        return true;
    }


    public function stem(): bool
    {
        $this->B_is_noun = true;
        $this->B_is_verb = true;
        $this->B_is_defined = false;
        $v_1 = $this->cursor;
        $this->r_Checks1();
        $this->cursor = $v_1;
        $this->r_Normalize_pre();
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_2 = $this->limit - $this->cursor;
        $v_3 = $this->limit - $this->cursor;
        if (!$this->B_is_verb) {
            goto lab1;
        }
        $v_4 = $this->limit - $this->cursor;
        $v_5 = 1;
        while (true) {
            $v_6 = $this->limit - $this->cursor;
            if (!$this->r_Suffix_Verb_Step1()) {
                goto lab3;
            }
            $v_5--;
            continue;
        lab3:
            $this->cursor = $this->limit - $v_6;
            break;
        }
        if ($v_5 > 0) {
            goto lab2;
        }
        $v_7 = $this->limit - $this->cursor;
        if (!$this->r_Suffix_Verb_Step2a()) {
            goto lab4;
        }
        goto lab5;
    lab4:
        $this->cursor = $this->limit - $v_7;
        if (!$this->r_Suffix_Verb_Step2c()) {
            goto lab6;
        }
        goto lab5;
    lab6:
        $this->cursor = $this->limit - $v_7;
        if ($this->cursor <= $this->limit_backward) {
            goto lab2;
        }
        $this->dec_cursor();
    lab5:
        goto lab7;
    lab2:
        $this->cursor = $this->limit - $v_4;
        if (!$this->r_Suffix_Verb_Step2b()) {
            goto lab8;
        }
        goto lab7;
    lab8:
        $this->cursor = $this->limit - $v_4;
        if (!$this->r_Suffix_Verb_Step2a()) {
            goto lab1;
        }
    lab7:
        goto lab9;
    lab1:
        $this->cursor = $this->limit - $v_3;
        if (!$this->B_is_noun) {
            goto lab10;
        }
        $v_8 = $this->limit - $this->cursor;
        $v_9 = $this->limit - $this->cursor;
        if (!$this->r_Suffix_Noun_Step2c2()) {
            goto lab12;
        }
        goto lab13;
    lab12:
        $this->cursor = $this->limit - $v_9;
        if ($this->B_is_defined) {
            goto lab14;
        }
        if (!$this->r_Suffix_Noun_Step1a()) {
            goto lab14;
        }
        $v_10 = $this->limit - $this->cursor;
        if (!$this->r_Suffix_Noun_Step2a()) {
            goto lab15;
        }
        goto lab16;
    lab15:
        $this->cursor = $this->limit - $v_10;
        if (!$this->r_Suffix_Noun_Step2b()) {
            goto lab17;
        }
        goto lab16;
    lab17:
        $this->cursor = $this->limit - $v_10;
        if (!$this->r_Suffix_Noun_Step2c1()) {
            goto lab18;
        }
        goto lab16;
    lab18:
        $this->cursor = $this->limit - $v_10;
        if ($this->cursor <= $this->limit_backward) {
            goto lab14;
        }
        $this->dec_cursor();
    lab16:
        goto lab13;
    lab14:
        $this->cursor = $this->limit - $v_9;
        if (!$this->r_Suffix_Noun_Step1b()) {
            goto lab19;
        }
        $v_11 = $this->limit - $this->cursor;
        if (!$this->r_Suffix_Noun_Step2a()) {
            goto lab20;
        }
        goto lab21;
    lab20:
        $this->cursor = $this->limit - $v_11;
        if (!$this->r_Suffix_Noun_Step2b()) {
            goto lab22;
        }
        goto lab21;
    lab22:
        $this->cursor = $this->limit - $v_11;
        if (!$this->r_Suffix_Noun_Step2c1()) {
            goto lab19;
        }
    lab21:
        goto lab13;
    lab19:
        $this->cursor = $this->limit - $v_9;
        if ($this->B_is_defined) {
            goto lab23;
        }
        if (!$this->r_Suffix_Noun_Step2a()) {
            goto lab23;
        }
        goto lab13;
    lab23:
        $this->cursor = $this->limit - $v_9;
        if (!$this->r_Suffix_Noun_Step2b()) {
            $this->cursor = $this->limit - $v_8;
            goto lab11;
        }
    lab13:
    lab11:
        if (!$this->r_Suffix_Noun_Step3()) {
            goto lab10;
        }
        goto lab9;
    lab10:
        $this->cursor = $this->limit - $v_3;
        if (!$this->r_Suffix_All_alef_maqsura()) {
            goto lab0;
        }
    lab9:
    lab0:
        $this->cursor = $this->limit - $v_2;
        $this->cursor = $this->limit_backward;
        $v_12 = $this->cursor;
        $v_13 = $this->cursor;
        if (!$this->r_Prefix_Step1()) {
            $this->cursor = $v_13;
            goto lab25;
        }
    lab25:
        $v_14 = $this->cursor;
        if (!$this->r_Prefix_Step2()) {
            $this->cursor = $v_14;
            goto lab26;
        }
    lab26:
        $v_15 = $this->cursor;
        if (!$this->r_Prefix_Step3a_Noun()) {
            goto lab27;
        }
        goto lab28;
    lab27:
        $this->cursor = $v_15;
        if (!$this->B_is_noun) {
            goto lab29;
        }
        if (!$this->r_Prefix_Step3b_Noun()) {
            goto lab29;
        }
        goto lab28;
    lab29:
        $this->cursor = $v_15;
        if (!$this->B_is_verb) {
            goto lab24;
        }
        $v_16 = $this->cursor;
        if (!$this->r_Prefix_Step3_Verb()) {
            $this->cursor = $v_16;
            goto lab30;
        }
    lab30:
        if (!$this->r_Prefix_Step4_Verb()) {
            goto lab24;
        }
    lab28:
    lab24:
        $this->cursor = $v_12;
        $this->r_Normalize_post();
        return true;
    }
}
