<?php
declare(strict_types=1);

/**
 * Hindi Snowball stemmer generated from upstream Snowball sources.
 *
 * Provenance:
 * - Source algorithm: snowballstem/snowball algorithms/hindi.sbl.
 * - Reference generator: Snowball 3.1.1 PHP output from upstream commit 571875488db025b82aaff0b510a3684df925daa3.
 * - Verified fixture data: /home/claude/.cache/snowball-data/hindi/voc.txt and
 *   /home/claude/.cache/snowball-data/hindi/output.txt
 *   (snowballstem/snowball-data hindi), 65,118 line pairs.
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
final class WP_FTS_HindiSnowballStemmer extends WP_FTS_SnowballGeneratedStemmer
{
    public const VARIANT = 'snowball-hindi';
    public const SOURCE_IDENTITY = 'Snowball Hindi generated from algorithms/hindi.sbl by Snowball 3.1.1; upstream snowball commit 571875488db025b82aaff0b510a3684df925daa3; verified against /home/claude/.cache/snowball-data/hindi/voc.txt and /home/claude/.cache/snowball-data/hindi/output.txt with 65,118 line pairs';

    public function source_identity(): string
    {
        return self::SOURCE_IDENTITY;
    }

    private const A_0 = [
        ["\u{940}", -1, -1],
        ["\u{942}\u{902}\u{917}\u{940}", 0, -1],
        ["\u{947}\u{902}\u{917}\u{940}", 0, -1],
        ["\u{90A}\u{902}\u{917}\u{940}", 0, -1],
        ["\u{906}\u{90A}\u{902}\u{917}\u{940}", 3, -1],
        ["\u{93E}\u{90A}\u{902}\u{917}\u{940}", 3, -1],
        ["\u{90F}\u{902}\u{917}\u{940}", 0, -1],
        ["\u{906}\u{90F}\u{902}\u{917}\u{940}", 6, -1],
        ["\u{93E}\u{90F}\u{902}\u{917}\u{940}", 6, -1],
        ["\u{947}\u{917}\u{940}", 0, -1],
        ["\u{94B}\u{917}\u{940}", 0, -1],
        ["\u{90F}\u{917}\u{940}", 0, -1],
        ["\u{906}\u{90F}\u{917}\u{940}", 11, -1],
        ["\u{93E}\u{90F}\u{917}\u{940}", 11, -1],
        ["\u{913}\u{917}\u{940}", 0, -1],
        ["\u{906}\u{913}\u{917}\u{940}", 14, -1],
        ["\u{93E}\u{913}\u{917}\u{940}", 14, -1],
        ["\u{924}\u{940}", 0, -1, 'r_CONSONANT'],
        ["\u{905}\u{924}\u{940}", 17, -1],
        ["\u{906}\u{924}\u{940}", 17, -1],
        ["\u{93E}\u{924}\u{940}", 17, -1],
        ["\u{928}\u{940}", 0, -1, 'r_CONSONANT'],
        ["\u{905}\u{928}\u{940}", 21, -1],
        ["\u{906}\u{901}", -1, -1],
        ["\u{93E}\u{901}", -1, -1],
        ["\u{907}\u{92F}\u{93E}\u{901}", 24, -1],
        ["\u{906}\u{907}\u{92F}\u{93E}\u{901}", 25, -1],
        ["\u{93E}\u{907}\u{92F}\u{93E}\u{901}", 25, -1],
        ["\u{93F}\u{92F}\u{93E}\u{901}", 24, -1],
        ["\u{941}", -1, -1],
        ["\u{940}\u{902}", -1, -1],
        ["\u{924}\u{940}\u{902}", 30, -1, 'r_CONSONANT'],
        ["\u{905}\u{924}\u{940}\u{902}", 31, -1],
        ["\u{906}\u{924}\u{940}\u{902}", 31, -1],
        ["\u{93E}\u{924}\u{940}\u{902}", 31, -1],
        ["\u{906}\u{902}", -1, -1],
        ["\u{941}\u{906}\u{902}", 35, -1],
        ["\u{909}\u{906}\u{902}", 35, -1],
        ["\u{947}\u{902}", -1, -1],
        ["\u{908}\u{902}", -1, -1],
        ["\u{906}\u{908}\u{902}", 39, -1],
        ["\u{93E}\u{908}\u{902}", 39, -1],
        ["\u{94B}\u{902}", -1, -1],
        ["\u{907}\u{92F}\u{94B}\u{902}", 42, -1],
        ["\u{906}\u{907}\u{92F}\u{94B}\u{902}", 43, -1],
        ["\u{93E}\u{907}\u{92F}\u{94B}\u{902}", 43, -1],
        ["\u{93F}\u{92F}\u{94B}\u{902}", 42, -1],
        ["\u{90F}\u{902}", -1, -1],
        ["\u{941}\u{90F}\u{902}", 47, -1],
        ["\u{906}\u{90F}\u{902}", 47, -1],
        ["\u{909}\u{90F}\u{902}", 47, -1],
        ["\u{93E}\u{90F}\u{902}", 47, -1],
        ["\u{924}\u{93E}\u{90F}\u{902}", 51, -1, 'r_CONSONANT'],
        ["\u{905}\u{924}\u{93E}\u{90F}\u{902}", 52, -1],
        ["\u{928}\u{93E}\u{90F}\u{902}", 51, -1, 'r_CONSONANT'],
        ["\u{905}\u{928}\u{93E}\u{90F}\u{902}", 54, -1],
        ["\u{913}\u{902}", -1, -1],
        ["\u{941}\u{913}\u{902}", 56, -1],
        ["\u{906}\u{913}\u{902}", 56, -1],
        ["\u{909}\u{913}\u{902}", 56, -1],
        ["\u{93E}\u{913}\u{902}", 56, -1],
        ["\u{924}\u{93E}\u{913}\u{902}", 60, -1, 'r_CONSONANT'],
        ["\u{905}\u{924}\u{93E}\u{913}\u{902}", 61, -1],
        ["\u{928}\u{93E}\u{913}\u{902}", 60, -1, 'r_CONSONANT'],
        ["\u{905}\u{928}\u{93E}\u{913}\u{902}", 63, -1],
        ["\u{93E}\u{902}", -1, -1],
        ["\u{907}\u{92F}\u{93E}\u{902}", 65, -1],
        ["\u{906}\u{907}\u{92F}\u{93E}\u{902}", 66, -1],
        ["\u{93E}\u{907}\u{92F}\u{93E}\u{902}", 66, -1],
        ["\u{93F}\u{92F}\u{93E}\u{902}", 65, -1],
        ["\u{942}", -1, -1],
        ["\u{905}", -1, -1],
        ["\u{906}", -1, -1],
        ["\u{907}", -1, -1],
        ["\u{947}", -1, -1],
        ["\u{947}\u{902}\u{917}\u{947}", 74, -1],
        ["\u{90F}\u{902}\u{917}\u{947}", 74, -1],
        ["\u{906}\u{90F}\u{902}\u{917}\u{947}", 76, -1],
        ["\u{93E}\u{90F}\u{902}\u{917}\u{947}", 76, -1],
        ["\u{94B}\u{917}\u{947}", 74, -1],
        ["\u{913}\u{917}\u{947}", 74, -1],
        ["\u{906}\u{913}\u{917}\u{947}", 80, -1],
        ["\u{93E}\u{913}\u{917}\u{947}", 80, -1],
        ["\u{924}\u{947}", 74, -1, 'r_CONSONANT'],
        ["\u{905}\u{924}\u{947}", 83, -1],
        ["\u{906}\u{924}\u{947}", 83, -1],
        ["\u{93E}\u{924}\u{947}", 83, -1],
        ["\u{928}\u{947}", 74, -1, 'r_CONSONANT'],
        ["\u{905}\u{928}\u{947}", 87, -1],
        ["\u{906}\u{928}\u{947}", 87, -1],
        ["\u{93E}\u{928}\u{947}", 87, -1],
        ["\u{908}", -1, -1],
        ["\u{906}\u{908}", 91, -1],
        ["\u{93E}\u{908}", 91, -1],
        ["\u{909}", -1, -1],
        ["\u{90A}", -1, -1],
        ["\u{94B}", -1, -1],
        ["\u{94D}", -1, -1],
        ["\u{90F}", -1, -1],
        ["\u{906}\u{90F}", 98, -1],
        ["\u{907}\u{90F}", 98, -1],
        ["\u{906}\u{907}\u{90F}", 100, -1],
        ["\u{93E}\u{907}\u{90F}", 100, -1],
        ["\u{93E}\u{90F}", 98, -1],
        ["\u{93F}\u{90F}", 98, -1],
        ["\u{913}", -1, -1],
        ["\u{906}\u{913}", 105, -1],
        ["\u{93E}\u{913}", 105, -1],
        ["\u{915}\u{930}", -1, -1, 'r_CONSONANT'],
        ["\u{905}\u{915}\u{930}", 108, -1],
        ["\u{906}\u{915}\u{930}", 108, -1],
        ["\u{93E}\u{915}\u{930}", 108, -1],
        ["\u{93E}", -1, -1],
        ["\u{942}\u{902}\u{917}\u{93E}", 112, -1],
        ["\u{90A}\u{902}\u{917}\u{93E}", 112, -1],
        ["\u{906}\u{90A}\u{902}\u{917}\u{93E}", 114, -1],
        ["\u{93E}\u{90A}\u{902}\u{917}\u{93E}", 114, -1],
        ["\u{947}\u{917}\u{93E}", 112, -1],
        ["\u{90F}\u{917}\u{93E}", 112, -1],
        ["\u{906}\u{90F}\u{917}\u{93E}", 118, -1],
        ["\u{93E}\u{90F}\u{917}\u{93E}", 118, -1],
        ["\u{924}\u{93E}", 112, -1, 'r_CONSONANT'],
        ["\u{905}\u{924}\u{93E}", 121, -1],
        ["\u{906}\u{924}\u{93E}", 121, -1],
        ["\u{93E}\u{924}\u{93E}", 121, -1],
        ["\u{928}\u{93E}", 112, -1, 'r_CONSONANT'],
        ["\u{905}\u{928}\u{93E}", 125, -1],
        ["\u{906}\u{928}\u{93E}", 125, -1],
        ["\u{93E}\u{928}\u{93E}", 125, -1],
        ["\u{906}\u{92F}\u{93E}", 112, -1],
        ["\u{93E}\u{92F}\u{93E}", 112, -1],
        ["\u{93F}", -1, -1]
    ];

    private const G_consonant = ["\u{915}"=>true, "\u{916}"=>true, "\u{917}"=>true, "\u{918}"=>true, "\u{919}"=>true, "\u{91A}"=>true, "\u{91B}"=>true, "\u{91C}"=>true, "\u{91D}"=>true, "\u{91E}"=>true, "\u{91F}"=>true, "\u{920}"=>true, "\u{921}"=>true, "\u{922}"=>true, "\u{923}"=>true, "\u{924}"=>true, "\u{925}"=>true, "\u{926}"=>true, "\u{927}"=>true, "\u{928}"=>true, "\u{929}"=>true, "\u{92A}"=>true, "\u{92B}"=>true, "\u{92C}"=>true, "\u{92D}"=>true, "\u{92E}"=>true, "\u{92F}"=>true, "\u{930}"=>true, "\u{931}"=>true, "\u{932}"=>true, "\u{933}"=>true, "\u{934}"=>true, "\u{935}"=>true, "\u{936}"=>true, "\u{937}"=>true, "\u{938}"=>true, "\u{939}"=>true, "\u{93C}"=>true, "\u{958}"=>true, "\u{959}"=>true, "\u{95A}"=>true, "\u{95B}"=>true, "\u{95C}"=>true, "\u{95D}"=>true, "\u{95E}"=>true, "\u{95F}"=>true];



    protected function r_CONSONANT(): bool
    {
        return $this->in_grouping_b(self::G_consonant);
    }


    public function stem(): bool
    {
        if ($this->cursor >= $this->limit) {
            return false;
        }
        $this->inc_cursor();
        $this->limit_backward = $this->cursor;
        $this->cursor = $this->limit;
        $this->ket = $this->cursor;
        if ($this->find_among_b(self::A_0) === 0) {
            return false;
        }
        $this->bra = $this->cursor;
        $this->slice_del();
        $this->cursor = $this->limit_backward;
        return true;
    }
}
