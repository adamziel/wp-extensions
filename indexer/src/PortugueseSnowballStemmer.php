<?php
declare(strict_types=1);

/**
 * Portuguese Snowball stemmer generated from upstream Snowball sources.
 *
 * Provenance:
 * - Source algorithm: snowballstem/snowball algorithms/portuguese.sbl.
 * - Reference generator: Snowball 3.1.1 PHP output from upstream commit 571875488db025b82aaff0b510a3684df925daa3.
 * - Verified fixture data: snowballstem/snowball-data portuguese voc.txt and output.txt, 32,016 line pairs.
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

final class WP_FTS_PortugueseSnowballStemmer extends WP_FTS_SnowballGeneratedStemmer
{
    public const VARIANT = 'snowball-portuguese';
    public const SOURCE_IDENTITY = 'Snowball Portuguese generated from algorithms/portuguese.sbl by Snowball 3.1.1; upstream snowball commit 571875488db025b82aaff0b510a3684df925daa3; verified against snowball-data portuguese fixtures with 32,016 line pairs';

    public function source_identity(): string
    {
        return self::SOURCE_IDENTITY;
    }

    private const A_0 = [
        ["", -1, 3],
        ["\u{E3}", 0, 1],
        ["\u{F5}", 0, 2]
    ];

    private const A_1 = [
        ["", -1, 3],
        ["a~", 0, 1],
        ["o~", 0, 2]
    ];

    private const A_2 = [
        ["ic", -1, -1],
        ["ad", -1, -1],
        ["os", -1, -1],
        ["iv", -1, 1]
    ];

    private const A_3 = [
        ["ante", -1, 1],
        ["avel", -1, 1],
        ["\u{ED}vel", -1, 1]
    ];

    private const A_4 = [
        ["ic", -1, 1],
        ["abil", -1, 1],
        ["iv", -1, 1]
    ];

    private const A_5 = [
        ["ica", -1, 1],
        ["\u{E2}ncia", -1, 1],
        ["\u{EA}ncia", -1, 4],
        ["logia", -1, 2],
        ["ira", -1, 9],
        ["adora", -1, 1],
        ["osa", -1, 1],
        ["ista", -1, 1],
        ["iva", -1, 8],
        ["eza", -1, 1],
        ["idade", -1, 7],
        ["ante", -1, 1],
        ["mente", -1, 6],
        ["amente", 12, 5],
        ["\u{E1}vel", -1, 1],
        ["\u{ED}vel", -1, 1],
        ["ico", -1, 1],
        ["ismo", -1, 1],
        ["oso", -1, 1],
        ["amento", -1, 1],
        ["imento", -1, 1],
        ["ivo", -1, 8],
        ["a\u{E7}a~o", -1, 1],
        ["u\u{E7}a~o", -1, 3],
        ["ador", -1, 1],
        ["icas", -1, 1],
        ["\u{EA}ncias", -1, 4],
        ["logias", -1, 2],
        ["iras", -1, 9],
        ["adoras", -1, 1],
        ["osas", -1, 1],
        ["istas", -1, 1],
        ["ivas", -1, 8],
        ["ezas", -1, 1],
        ["idades", -1, 7],
        ["adores", -1, 1],
        ["antes", -1, 1],
        ["a\u{E7}o~es", -1, 1],
        ["u\u{E7}o~es", -1, 3],
        ["icos", -1, 1],
        ["ismos", -1, 1],
        ["osos", -1, 1],
        ["amentos", -1, 1],
        ["imentos", -1, 1],
        ["ivos", -1, 8]
    ];

    private const A_6 = [
        ["ada", -1, 1],
        ["ida", -1, 1],
        ["ia", -1, 1],
        ["aria", 2, 1],
        ["eria", 2, 1],
        ["iria", 2, 1],
        ["ara", -1, 1],
        ["era", -1, 1],
        ["ira", -1, 1],
        ["ava", -1, 1],
        ["asse", -1, 1],
        ["esse", -1, 1],
        ["isse", -1, 1],
        ["aste", -1, 1],
        ["este", -1, 1],
        ["iste", -1, 1],
        ["ei", -1, 1],
        ["arei", 16, 1],
        ["erei", 16, 1],
        ["irei", 16, 1],
        ["am", -1, 1],
        ["iam", 20, 1],
        ["ariam", 21, 1],
        ["eriam", 21, 1],
        ["iriam", 21, 1],
        ["aram", 20, 1],
        ["eram", 20, 1],
        ["iram", 20, 1],
        ["avam", 20, 1],
        ["em", -1, 1],
        ["arem", 29, 1],
        ["erem", 29, 1],
        ["irem", 29, 1],
        ["assem", 29, 1],
        ["essem", 29, 1],
        ["issem", 29, 1],
        ["ado", -1, 1],
        ["ido", -1, 1],
        ["ando", -1, 1],
        ["endo", -1, 1],
        ["indo", -1, 1],
        ["ara~o", -1, 1],
        ["era~o", -1, 1],
        ["ira~o", -1, 1],
        ["ar", -1, 1],
        ["er", -1, 1],
        ["ir", -1, 1],
        ["as", -1, 1],
        ["adas", 47, 1],
        ["idas", 47, 1],
        ["ias", 47, 1],
        ["arias", 50, 1],
        ["erias", 50, 1],
        ["irias", 50, 1],
        ["aras", 47, 1],
        ["eras", 47, 1],
        ["iras", 47, 1],
        ["avas", 47, 1],
        ["es", -1, 1],
        ["ardes", 58, 1],
        ["erdes", 58, 1],
        ["irdes", 58, 1],
        ["ares", 58, 1],
        ["eres", 58, 1],
        ["ires", 58, 1],
        ["asses", 58, 1],
        ["esses", 58, 1],
        ["isses", 58, 1],
        ["astes", 58, 1],
        ["estes", 58, 1],
        ["istes", 58, 1],
        ["is", -1, 1],
        ["ais", 71, 1],
        ["eis", 71, 1],
        ["areis", 73, 1],
        ["ereis", 73, 1],
        ["ireis", 73, 1],
        ["\u{E1}reis", 73, 1],
        ["\u{E9}reis", 73, 1],
        ["\u{ED}reis", 73, 1],
        ["\u{E1}sseis", 73, 1],
        ["\u{E9}sseis", 73, 1],
        ["\u{ED}sseis", 73, 1],
        ["\u{E1}veis", 73, 1],
        ["\u{ED}eis", 73, 1],
        ["ar\u{ED}eis", 84, 1],
        ["er\u{ED}eis", 84, 1],
        ["ir\u{ED}eis", 84, 1],
        ["ados", -1, 1],
        ["idos", -1, 1],
        ["amos", -1, 1],
        ["\u{E1}ramos", 90, 1],
        ["\u{E9}ramos", 90, 1],
        ["\u{ED}ramos", 90, 1],
        ["\u{E1}vamos", 90, 1],
        ["\u{ED}amos", 90, 1],
        ["ar\u{ED}amos", 95, 1],
        ["er\u{ED}amos", 95, 1],
        ["ir\u{ED}amos", 95, 1],
        ["emos", -1, 1],
        ["aremos", 99, 1],
        ["eremos", 99, 1],
        ["iremos", 99, 1],
        ["\u{E1}ssemos", 99, 1],
        ["\u{EA}ssemos", 99, 1],
        ["\u{ED}ssemos", 99, 1],
        ["imos", -1, 1],
        ["armos", -1, 1],
        ["ermos", -1, 1],
        ["irmos", -1, 1],
        ["\u{E1}mos", -1, 1],
        ["ar\u{E1}s", -1, 1],
        ["er\u{E1}s", -1, 1],
        ["ir\u{E1}s", -1, 1],
        ["eu", -1, 1],
        ["iu", -1, 1],
        ["ou", -1, 1],
        ["ar\u{E1}", -1, 1],
        ["er\u{E1}", -1, 1],
        ["ir\u{E1}", -1, 1]
    ];

    private const A_7 = [
        ["a", -1, 1],
        ["i", -1, 1],
        ["o", -1, 1],
        ["os", -1, 1],
        ["\u{E1}", -1, 1],
        ["\u{ED}", -1, 1],
        ["\u{F3}", -1, 1]
    ];

    private const A_8 = [
        ["e", -1, 1],
        ["\u{E7}", -1, 2],
        ["\u{E9}", -1, 1],
        ["\u{EA}", -1, 1]
    ];

    private const G_v = ["a"=>true, "e"=>true, "i"=>true, "o"=>true, "u"=>true, "\u{E1}"=>true, "\u{E2}"=>true, "\u{E9}"=>true, "\u{EA}"=>true, "\u{ED}"=>true, "\u{F3}"=>true, "\u{F4}"=>true, "\u{FA}"=>true];

    private int $I_p2 = 0;
    private int $I_p1 = 0;
    private int $I_pV = 0;



    protected function r_prelude(): bool
    {
        while (true) {
            $v_1 = $this->cursor;
            $this->bra = $this->cursor;
            $among_var = $this->find_among(self::A_0);
            $this->ket = $this->cursor;
            switch ($among_var) {
                case 1:
                    $this->slice_from("a~");
                    break;
                case 2:
                    $this->slice_from("o~");
                    break;
                case 3:
                    if ($this->cursor >= $this->limit) {
                        goto lab0;
                    }
                    $this->inc_cursor();
                    break;
            }
            continue;
        lab0:
            $this->cursor = $v_1;
            break;
        }
        return true;
    }


    protected function r_mark_regions(): bool
    {
        $this->I_pV = $this->limit;
        $this->I_p1 = $this->limit;
        $this->I_p2 = $this->limit;
        $v_1 = $this->cursor;
        $v_2 = $this->cursor;
        if (!($this->in_grouping(self::G_v))) {
            goto lab1;
        }
        $v_3 = $this->cursor;
        if (!($this->out_grouping(self::G_v))) {
            goto lab2;
        }
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab2;
        }
        $this->inc_cursor();
        goto lab3;
    lab2:
        $this->cursor = $v_3;
        if (!($this->in_grouping(self::G_v))) {
            goto lab1;
        }
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab1;
        }
        $this->inc_cursor();
    lab3:
        goto lab4;
    lab1:
        $this->cursor = $v_2;
        if (!($this->out_grouping(self::G_v))) {
            goto lab0;
        }
        $v_4 = $this->cursor;
        if (!($this->out_grouping(self::G_v))) {
            goto lab5;
        }
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab5;
        }
        $this->inc_cursor();
        goto lab6;
    lab5:
        $this->cursor = $v_4;
        if (!($this->in_grouping(self::G_v))) {
            goto lab0;
        }
        if ($this->cursor >= $this->limit) {
            goto lab0;
        }
        $this->inc_cursor();
    lab6:
    lab4:
        $this->I_pV = $this->cursor;
    lab0:
        $this->cursor = $v_1;
        $v_5 = $this->cursor;
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab7;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab7;
        }
        $this->inc_cursor();
        $this->I_p1 = $this->cursor;
        if (!$this->go_out_grouping(self::G_v)) {
            goto lab7;
        }
        $this->inc_cursor();
        if (!$this->go_in_grouping(self::G_v)) {
            goto lab7;
        }
        $this->inc_cursor();
        $this->I_p2 = $this->cursor;
    lab7:
        $this->cursor = $v_5;
        return true;
    }


    protected function r_postlude(): bool
    {
        while (true) {
            $v_1 = $this->cursor;
            $this->bra = $this->cursor;
            $among_var = $this->find_among(self::A_1);
            $this->ket = $this->cursor;
            switch ($among_var) {
                case 1:
                    $this->slice_from("\u{E3}");
                    break;
                case 2:
                    $this->slice_from("\u{F5}");
                    break;
                case 3:
                    if ($this->cursor >= $this->limit) {
                        goto lab0;
                    }
                    $this->inc_cursor();
                    break;
            }
            continue;
        lab0:
            $this->cursor = $v_1;
            break;
        }
        return true;
    }


    protected function r_RV(): bool
    {
        return $this->I_pV <= $this->cursor;
    }


    protected function r_R2(): bool
    {
        return $this->I_p2 <= $this->cursor;
    }


    protected function r_standard_suffix(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_5);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_from("log");
                break;
            case 3:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_from("u");
                break;
            case 4:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_from("ente");
                break;
            case 5:
                if ($this->I_p1 > $this->cursor) {
                    return false;
                }
                $this->slice_del();
                $v_1 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                $among_var = $this->find_among_b(self::A_2);
                if (0 === $among_var) {
                    $this->cursor = $this->limit - $v_1;
                    goto lab0;
                }
                $this->bra = $this->cursor;
                if (!$this->r_R2()) {
                    $this->cursor = $this->limit - $v_1;
                    goto lab0;
                }
                $this->slice_del();
                switch ($among_var) {
                    case 1:
                        $this->ket = $this->cursor;
                        if (!($this->eq_s_b("at"))) {
                            $this->cursor = $this->limit - $v_1;
                            goto lab0;
                        }
                        $this->bra = $this->cursor;
                        if (!$this->r_R2()) {
                            $this->cursor = $this->limit - $v_1;
                            goto lab0;
                        }
                        $this->slice_del();
                        break;
                }
            lab0:
                break;
            case 6:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                $v_2 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                if ($this->find_among_b(self::A_3) === 0) {
                    $this->cursor = $this->limit - $v_2;
                    goto lab1;
                }
                $this->bra = $this->cursor;
                if (!$this->r_R2()) {
                    $this->cursor = $this->limit - $v_2;
                    goto lab1;
                }
                $this->slice_del();
            lab1:
                break;
            case 7:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                $v_3 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                if ($this->find_among_b(self::A_4) === 0) {
                    $this->cursor = $this->limit - $v_3;
                    goto lab2;
                }
                $this->bra = $this->cursor;
                if (!$this->r_R2()) {
                    $this->cursor = $this->limit - $v_3;
                    goto lab2;
                }
                $this->slice_del();
            lab2:
                break;
            case 8:
                if (!$this->r_R2()) {
                    return false;
                }
                $this->slice_del();
                $v_4 = $this->limit - $this->cursor;
                $this->ket = $this->cursor;
                if (!($this->eq_s_b("at"))) {
                    $this->cursor = $this->limit - $v_4;
                    goto lab3;
                }
                $this->bra = $this->cursor;
                if (!$this->r_R2()) {
                    $this->cursor = $this->limit - $v_4;
                    goto lab3;
                }
                $this->slice_del();
            lab3:
                break;
            case 9:
                if (!$this->r_RV()) {
                    return false;
                }
                if (!($this->eq_s_b("e"))) {
                    return false;
                }
                $this->slice_from("ir");
                break;
        }
        return true;
    }


    protected function r_verb_suffix(): bool
    {
        if ($this->cursor < $this->I_pV) {
            return false;
        }
        $v_1 = $this->limit_backward;
        $this->limit_backward = $this->I_pV;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_6) === 0) {
            $this->limit_backward = $v_1;
            return false;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $this->limit_backward = $v_1;
        return true;
    }


    protected function r_residual_suffix(): bool
    {
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_7) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        if (!$this->r_RV()) {
            return false;
        }
        $this->slice_del();
        return true;
    }


    protected function r_residual_form(): bool
    {
        $this->ket = $this->cursor;
        $among_var = $this->find_among_b(self::A_8);
        if (0 === $among_var) {
            return false;
        }
        $this->bra = $this->cursor;
        switch ($among_var) {
            case 1:
                if (!$this->r_RV()) {
                    return false;
                }
                $this->slice_del();
                $this->ket = $this->cursor;
                $v_1 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("u"))) {
                    goto lab0;
                }
                $this->bra = $this->cursor;
                $v_2 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("g"))) {
                    goto lab0;
                }
                $this->cursor = $this->limit - $v_2;
                goto lab1;
            lab0:
                $this->cursor = $this->limit - $v_1;
                if (!($this->eq_s_b("i"))) {
                    return false;
                }
                $this->bra = $this->cursor;
                $v_3 = $this->limit - $this->cursor;
                if (!($this->eq_s_b("c"))) {
                    return false;
                }
                $this->cursor = $this->limit - $v_3;
            lab1:
                if (!$this->r_RV()) {
                    return false;
                }
                $this->slice_del();
                break;
            case 2:
                $this->slice_from("c");
                break;
        }
        return true;
    }


    public function stem(): bool
    {
        $v_1 = $this->cursor;
        $this->r_prelude();
        $this->cursor = $v_1;
        $this->r_mark_regions();
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $v_2 = $this->limit - $this->cursor;
        $v_3 = $this->limit - $this->cursor;
        $v_4 = $this->limit - $this->cursor;
        $v_5 = $this->limit - $this->cursor;
        if (!$this->r_standard_suffix()) {
            goto lab2;
        }
        goto lab3;
    lab2:
        $this->cursor = $this->limit - $v_5;
        if (!$this->r_verb_suffix()) {
            goto lab1;
        }
    lab3:
        $this->cursor = $this->limit - $v_4;
        $v_6 = $this->limit - $this->cursor;
        $this->ket = $this->cursor;
        if (!($this->eq_s_b("i"))) {
            goto lab4;
        }
        $this->bra = $this->cursor;
        $v_7 = $this->limit - $this->cursor;
        if (!($this->eq_s_b("c"))) {
            goto lab4;
        }
        $this->cursor = $this->limit - $v_7;
        if (!$this->r_RV()) {
            goto lab4;
        }
        $this->slice_del();
    lab4:
        $this->cursor = $this->limit - $v_6;
        goto lab5;
    lab1:
        $this->cursor = $this->limit - $v_3;
        if (!$this->r_residual_suffix()) {
            goto lab0;
        }
    lab5:
    lab0:
        $this->cursor = $this->limit - $v_2;
        $v_8 = $this->limit - $this->cursor;
        $this->r_residual_form();
        $this->cursor = $this->limit - $v_8;
        $this->cursor = $this->limit_backward;
        $v_9 = $this->cursor;
        $this->r_postlude();
        $this->cursor = $v_9;
        return true;
    }
}
