<?php
declare(strict_types=1);

/**
 * Indonesian Snowball stemmer generated from upstream Snowball sources.
 *
 * Provenance:
 * - Source algorithm: snowballstem/snowball algorithms/indonesian.sbl.
 * - Reference generator: Snowball 3.1.1 PHP output from upstream commit 571875488db025b82aaff0b510a3684df925daa3.
 * - Verified fixture data: snowballstem/snowball-data indonesian voc.txt and output.txt, 64,586 line pairs.
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
final class WP_FTS_IndonesianSnowballStemmer extends WP_FTS_SnowballGeneratedStemmer
{
    public const VARIANT = 'snowball-indonesian';
    public const SOURCE_IDENTITY = 'Snowball Indonesian generated from algorithms/indonesian.sbl by Snowball 3.1.1; upstream snowball commit 571875488db025b82aaff0b510a3684df925daa3; verified against snowball-data indonesian fixtures with 64,586 line pairs';

    public function source_identity(): string
    {
        return self::SOURCE_IDENTITY;
    }

    private const A_0 = [
        ["kah", -1, 1],
        ["lah", -1, 1],
        ["pun", -1, 1]
    ];

    private const A_1 = [
        ["nya", -1, 1],
        ["ku", -1, 1],
        ["mu", -1, 1]
    ];

    private const A_2 = [
        ["i", -1, 2],
        ["an", -1, 1]
    ];

    private const A_3 = [
        ["di", -1, 1],
        ["ke", -1, 3],
        ["me", -1, 1],
        ["mem", 2, 5],
        ["men", 2, 2],
        ["meng", 4, 1],
        ["pem", -1, 6],
        ["pen", -1, 4],
        ["peng", 7, 3],
        ["ter", -1, 1]
    ];

    private const A_4 = [
        ["be", -1, 2],
        ["pe", -1, 1]
    ];

    private const G_vowel = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true];

    private int $I_prefix = 0;
    private int $I_measure = 0;



    protected function r_remove_particle(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_0) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        --$this->I_measure;
        return true;
    }


    protected function r_remove_possessive_pronoun(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_1) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        --$this->I_measure;
        return true;
    }


    protected function r_remove_suffix(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_2);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                $v_1 = $this->limit - $this->cursor;
                if ($this->I_prefix === 3) {
                    goto lab0;
                }
                if ($this->I_prefix === 2) {
                    goto lab0;
                }
                if (!($this->eq_s_b("k"))) {
                    goto lab0;
                }
                $this->bra = $this->cursor;
                goto lab1;
            lab0:
                $this->cursor = $this->limit - $v_1;
                if ($this->I_prefix === 1) {
                    return false;
                }
            lab1:
                break;
            case 2:
                if ($this->I_prefix > 2) {
                    return false;
                }
                if (!($this->eq_s_b("s"))) {
                    goto lab2;
                }
                return false;
            lab2:
                break;
        }
        $this->slice_del();
        --$this->I_measure;
        return true;
    }


    protected function r_remove_first_order_prefix(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_3);
        if (0 === $among_var) {
            return false;
        }
        $this->ket = $this->cursor;
        switch ($among_var) {
            case 1:
                $this->slice_del();
                $this->I_prefix = 1;
                --$this->I_measure;
                break;
            case 2:
                $v_1 = $this->cursor;
                if (!($this->eq_s("y"))) {
                    goto lab0;
                }
                $v_2 = $this->cursor;
                if (!($this->in_grouping(self::G_vowel))) {
                    goto lab0;
                }
                $this->cursor = $v_2;
                $this->ket = $this->cursor;
                $this->slice_from("s");
                $this->I_prefix = 1;
                --$this->I_measure;
                goto lab1;
            lab0:
                $this->cursor = $v_1;
                $this->slice_del();
                $this->I_prefix = 1;
                --$this->I_measure;
            lab1:
                break;
            case 3:
                $this->slice_del();
                $this->I_prefix = 3;
                --$this->I_measure;
                break;
            case 4:
                $v_3 = $this->cursor;
                if (!($this->eq_s("y"))) {
                    goto lab2;
                }
                $v_4 = $this->cursor;
                if (!($this->in_grouping(self::G_vowel))) {
                    goto lab2;
                }
                $this->cursor = $v_4;
                $this->ket = $this->cursor;
                $this->slice_from("s");
                $this->I_prefix = 3;
                --$this->I_measure;
                goto lab3;
            lab2:
                $this->cursor = $v_3;
                $this->slice_del();
                $this->I_prefix = 3;
                --$this->I_measure;
            lab3:
                break;
            case 5:
                $this->I_prefix = 1;
                --$this->I_measure;
                $v_5 = $this->cursor;
                $v_6 = $this->cursor;
                if (!($this->in_grouping(self::G_vowel))) {
                    goto lab4;
                }
                $this->cursor = $v_6;
                $this->slice_from("p");
                goto lab5;
            lab4:
                $this->cursor = $v_5;
                $this->slice_del();
            lab5:
                break;
            case 6:
                $this->I_prefix = 3;
                --$this->I_measure;
                $v_7 = $this->cursor;
                $v_8 = $this->cursor;
                if (!($this->in_grouping(self::G_vowel))) {
                    goto lab6;
                }
                $this->cursor = $v_8;
                $this->slice_from("p");
                goto lab7;
            lab6:
                $this->cursor = $v_7;
                $this->slice_del();
            lab7:
                break;
        }
        return true;
    }


    protected function r_remove_second_order_prefix(): bool
    {
        $this->bra = $this->cursor;
        $among_var = $this->find_among(self::A_4);
        if (0 === $among_var) {
            return false;
        }
        switch ($among_var) {
            case 1:
                $v_1 = $this->cursor;
                if (!($this->eq_s("r"))) {
                    goto lab0;
                }
                $this->ket = $this->cursor;
                $this->I_prefix = 2;
                goto lab1;
            lab0:
                $this->cursor = $v_1;
                if (!($this->eq_s("l"))) {
                    goto lab2;
                }
                $this->ket = $this->cursor;
                if (!($this->eq_s("ajar"))) {
                    goto lab2;
                }
                goto lab1;
            lab2:
                $this->cursor = $v_1;
                $this->ket = $this->cursor;
                $this->I_prefix = 2;
            lab1:
                break;
            case 2:
                $v_2 = $this->cursor;
                if (!($this->eq_s("r"))) {
                    goto lab3;
                }
                $this->ket = $this->cursor;
                goto lab4;
            lab3:
                $this->cursor = $v_2;
                if (!($this->eq_s("l"))) {
                    goto lab5;
                }
                $this->ket = $this->cursor;
                if (!($this->eq_s("ajar"))) {
                    goto lab5;
                }
                goto lab4;
            lab5:
                $this->cursor = $v_2;
                $this->ket = $this->cursor;
                if (!($this->out_grouping(self::G_vowel))) {
                    return false;
                }
                if (!($this->eq_s("er"))) {
                    return false;
                }
            lab4:
                $this->I_prefix = 4;
                break;
        }
        --$this->I_measure;
        $this->slice_del();
        return true;
    }


    public function stem(): bool
    {
        $this->I_measure = 0;
        $v_1 = $this->cursor;
        while (true) {
            $v_2 = $this->cursor;
            if (!$this->go_out_grouping(self::G_vowel)) {
                goto lab1;
            }
            $this->inc_cursor();
            ++$this->I_measure;
            continue;
        lab1:
            $this->cursor = $v_2;
            break;
        }
    lab0:
        $this->cursor = $v_1;
        if ($this->I_measure <= 2) {
            return false;
        }
        $this->I_prefix = 0;
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_3 = $this->limit - $this->cursor;
        $this->r_remove_particle();
        $this->cursor = $this->limit - $v_3;
        if ($this->I_measure <= 2) {
            return false;
        }
        $v_4 = $this->limit - $this->cursor;
        $this->r_remove_possessive_pronoun();
        $this->cursor = $this->limit - $v_4;
        $this->cursor = $this->limit_backward;
        if ($this->I_measure <= 2) {
            return false;
        }
        $v_5 = $this->cursor;
        $v_6 = $this->cursor;
        if (!$this->r_remove_first_order_prefix()) {
            goto lab2;
        }
        $v_7 = $this->cursor;
        $v_8 = $this->cursor;
        if ($this->I_measure <= 2) {
            goto lab3;
        }
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        if (!$this->r_remove_suffix()) {
            goto lab3;
        }
        $this->cursor = $this->limit_backward;
        $this->cursor = $v_8;
        if ($this->I_measure <= 2) {
            goto lab3;
        }
        if (!$this->r_remove_second_order_prefix()) {
            goto lab3;
        }
    lab3:
        $this->cursor = $v_7;
        $this->cursor = $v_6;
        goto lab4;
    lab2:
        $this->cursor = $v_5;
        $v_9 = $this->cursor;
        $this->r_remove_second_order_prefix();
        $this->cursor = $v_9;
        $v_10 = $this->cursor;
        if ($this->I_measure <= 2) {
            goto lab5;
        }
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        if (!$this->r_remove_suffix()) {
            goto lab5;
        }
        $this->cursor = $this->limit_backward;
    lab5:
        $this->cursor = $v_10;
    lab4:
        return true;
    }
}
