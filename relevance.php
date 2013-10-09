<?

class player {

    const SCALA_KEEPER_MIN = 2.64;      //
    const SCALA_KEEPER_MAX = 11.35;     // 7.9 + 6.9 * 0.5;
    const SCALA_FIELDER_MIN = 7;        // 3 (skilllevel) * 5 (#skills)
    const SCALA_FIELDER_MAX = 29.97;    // 8.1 * 3.7;

    protected $_ALLROUNDER = array(
        -1 => 9999,
        1 => 9,
        2 => 12,
        3 => 15,
        4 => 18,
        5 => 21,
        6 => 24,
        7 => 27,
        8 => 31
    );

    function calculate_relevance($speciality, $skills, $ratings, $allrounder, $scouting_age) {
        $status = 0;
        if ($speciality > 0) $status = 1;

        $factor = (19 - $scouting_age) * 112 / (4 * 112);
        $age_factor = sqrt(sqrt(sqrt(sqrt(min(1, $factor * 1.3)))));

        #region determine skills and top3 cap
        $lowestTop3 = 10;
        $knownTop3 = array();
        $skills2 = array (3, 4, 5, 6, 7, 8);
        foreach ($skills2 as $i)
        {
            if ($skills[$i]['top3']) {
                $knownTop3[] = $i;
                if ($skills[$i]['max'] > 0) $lowestTop3 = min(floatval($skills[$i]['max']), $lowestTop3);
            }
        }
        if ($lowestTop3 != 10 && $lowestTop3 == floor($lowestTop3)) $lowestTop3 += 0.5;
        $values = array();
        foreach ($skills2 as $i) {
            $rating = -1;
            switch($i) {
                case 3: $rating = $ratings[4] / 1.0; break;
                case 4: $rating = $ratings[3] / 1.0; break;
                case 5: $rating = $ratings[5] / 1.5; break;
                case 6: $rating = $ratings[0] / 1.0; break;
                case 7: break;
                case 8: $rating = max($ratings[1] / 1.0, $ratings[2] / 1.5); break;
            }
            $skill = $skills[$i];
            $value = -1;
            $type = '';
            if (max($value, $skill['max']) > $value) {
                $value = $skill['max'];
                $type = 'max';
            }
            if ($skill['max'] < 0 && max($value, $skill['min_max']) > $value) {
                $value = $skill['min_max'];
                $type = 'min_max';
            }
            if ($skill['max'] < 0 && max($value, $skill['actual']) > $value) {
                $value = $skill['actual'];
                $type = 'actual';
            }
            if ($skill['max'] < 0 && $skill['actual'] < 0 && max($value, $skill['actual_estimation']) > $value) {
                $value = $skill['actual_estimation'];
                $type = 'actual_estimation';
            }
            if ($type == "" && $rating > 0) {
                $value = $rating;
                $type = 'rating';
            }
            if ($type == "") {
                $value = 8.1;
                $type = 'unknown';
            }
            $values[$i] = array(
                'value' => $value,
                'type' => $type,
            );
            $values[$i]['top3'] = $skill['top3'];
        }
        if ($values[6]['top3']) $status = 2;
        $rank = array();
        foreach ($skills2 as $i) {
            $value = $values[$i]['value'];
            $type = $values[$i]['type'];
            switch ($type) {
                case 'min_max':
                case 'actual_estimation':
                case 'actual':
                    $rank[$i]['value'] = min(8.1, $value + 3);
                    if ($i != 8 && $value >= 5) $status = 1;
                    if ($i == 8 && $value >= 7) $status = 1;
                    if ($i == 6 && $value >= 5) $status = 2;
                    break;
                case 'rating':
                    $rank[$i]['value'] = min(8.1, $value + 1.5);
                    break;
                case 'max':
                    if ($value == floor($value)) $rank[$i]['value'] = min(8.1, $value + 0.5);
                    else $rank[$i]['value'] = min(8.1, $value);
                    if ($i != 8 && $value >= 5) $status = 1;
                    if ($i == 8 && $value >= 7) $status = 1;
                    if ($i == 6 && $value >= 5) $status = 2;
                    break;
                case 'unknown':
                    if ($i == 6) $rank[$i]['value'] = 7.9;
                    else $rank[$i]['value'] = 8.1;
                    break;
            }
            $rank[$i]['value_ori'] = $value;
            $rank[$i]['type'] = $type;
            $rank[$i]['skill'] = $i;
        }
        hy_usort($rank, 'value', 'desc');
        $rank2 = array();
        foreach ($rank as $r) {
            $rank2[$r['skill']] = $r;
        }
        #endregion

        $min_pot_field = 0;
        $max_pot_field = 0;
        if ($status == 0 || $status == 1) {
            #region fieldplayer
            $limitedSkillsFielder = array();
            $count = 0;
            $allrounder_left = self::$_ALLROUNDER[$allrounder];
            $touched_skills = array();
            foreach ($knownTop3 as $skill) {
                $r = $rank2[$skill];
                $limitedSkillsFielder[$r['skill']] = $r;
                $allrounder_left = max(0, $allrounder_left - $r['value']);
                $touched_skills[] = $r['skill'];
            }
            foreach ($rank as $r) {
                if ($r['skill'] == 6) continue;
                if (count($knownTop3) + $count >= 3) break;
                $temp = min($r['value'], $allrounder_left);
                $r['value'] = $temp;
                $r['value_ori'] = min($r['value_ori'], $r['value']);
                $limitedSkillsFielder[$r['skill']] = $r;
                $allrounder_left = max(0, $allrounder_left - $temp);
                $count++;
                $touched_skills[] = $r['skill'];
            }
            foreach ($rank as $r) {
                if ($r['skill'] == 6) continue;
                if (!in_array($r['skill'], $touched_skills)) {
                    $r['value'] = min($r['value'], $lowestTop3);
                    $r['value_ori'] = min($r['value_ori'], $r['value']);
                    $limitedSkillsFielder[$r['skill']] = $r;
                }
            }
            $min_pot = 0;
            $max_pot = 0;
            #region scoring and defending skill does not match at all
            $skills_to_compare = array(5, 8);
            $skills_compared = array(5 => 0, 8 => 0);
            foreach ($skills_to_compare as $i) {
                $skills_compared[$i] += $limitedSkillsFielder[$i]['value'];
            }
            if ($skills_compared[5] > $skills_compared[8]) {
                $limitedSkillsFielder[8]['value'] = $limitedSkillsFielder[5]['value'] / 2;
                $limitedSkillsFielder[8]['value_ori'] = $limitedSkillsFielder[5]['value_ori'] / 2;
            } else {
                $limitedSkillsFielder[5]['value'] = $limitedSkillsFielder[8]['value'] / 2;
                $limitedSkillsFielder[5]['value_ori'] = $limitedSkillsFielder[8]['value'] / 2;
            }
            #endregion
            $skills2 = array(3, 4, 5, 7, 8);
            $best_skill = -1;
            $best_value = -1;
            $second_best_value = -1;
            foreach ($skills2 as $i) {
                $value = $limitedSkillsFielder[$i]['value'];
                $value_ori = $limitedSkillsFielder[$i]['value_ori'];
                switch ($limitedSkillsFielder[$i]['type']) {
                    case 'min_max':
                    case 'actual_estimation':
                    case 'actual':
                        $min_pot += $value_ori;
                        $max_pot += $value;
                        if ($value >= $best_value) {
                            $second_best_value = $best_value;
                            $best_skill = $i;
                            $best_value = $value;
                        } else if ($value >= $second_best_value) {
                            $second_best_value = $value;
                        }
                        break;
                    case 'rating':
                        $min_pot += $value_ori;
                        $max_pot += $value;
                        if ($value >= $best_value) {
                            $second_best_value = $best_value;
                            $best_skill = $i;
                            $best_value = $value;
                        } else if ($value >= $second_best_value) {
                            $second_best_value = $value;
                        }
                        break;
                    case 'max':
                        $min_pot += $value_ori;
                        $max_pot += $value;
                        if ($value >= $best_value) {
                            $second_best_value = $best_value;
                            $best_skill = $i;
                            $best_value = $value;
                        } else if ($value >= $second_best_value) {
                            $second_best_value = $value;
                        }
                        break;
                    case 'unknown':
                        $min_pot += 2;
                        $max_pot += $value_ori;
                        if ($value >= $best_value) {
                            $second_best_value = $best_value;
                            $best_skill = $i;
                            $best_value = $value;
                        } else if ($value >= $second_best_value) {
                            $second_best_value = $value;
                        }
                        break;
                }
            }
            $passing_best_skill_factor = 1;
            if ($best_skill == 7 && $best_value - $second_best_value > 0.5) {
                $passing_best_skill_factor = sqrt(($second_best_value +0.5)/ $best_value);
            }
            $best_skill_value_factor = sqrt($best_value / 8.1);
            $min_pot_field = $min_pot * $best_skill_value_factor * $passing_best_skill_factor;
            $max_pot_field = $max_pot * $best_skill_value_factor * $passing_best_skill_factor;
            #endregion
        }

        $min_pot_keeper = 0;
        $max_pot_keeper = 0;
        if ($status == 0 || $status == 2) {
            #region goalkeeper
            $limitedSkillsKeeper = array();
            $count = 0;
            $touched_skills = array();
            $allrounder_left = self::$_ALLROUNDER[$allrounder];
            foreach ($knownTop3 as $skill) {
                $r = $rank2[$skill];
                $limitedSkillsKeeper[$r['skill']] = $r;
                $allrounder_left = max(0, $allrounder_left - $r['value']);
                $touched_skills[] = $r['skill'];
            }
            if (count($knownTop3) < 3 && !in_array(6, $knownTop3)) {
                $r = $rank2[6];
                $temp = min($r['value'], $allrounder_left);
                $r['value'] = $temp;
                $r['value_ori'] = min($r['value_ori'], $r['value']);
                $limitedSkillsKeeper[$r['skill']] = $r;
                $allrounder_left = max(0, $allrounder_left - $temp);
                $count++;
                $touched_skills[] = $r['skill'];
            }
            foreach ($rank as $r) {
                if (count($knownTop3) + $count >= 3) break;
                if (!in_array($r['skill'], $touched_skills)) {
                    $temp = min($r['value'], $allrounder_left);
                    $r['value'] = $temp;
                    $r['value_ori'] = min($r['value_ori'], $r['value']);
                    $limitedSkillsKeeper[$r['skill']] = $r;
                    $allrounder_left = max(0, $allrounder_left - $temp);
                    $count++;
                    $touched_skills[] = $r['skill'];
                }
            }
            foreach ($rank as $r) {
                if (!in_array($r['skill'], $touched_skills)) {
                    $r['value'] = min($r['value'], $lowestTop3);
                    $r['value_ori'] = min($r['value_ori'], $r['value']);
                    $limitedSkillsKeeper[$r['skill']] = $r;
                }
            }
            $min_pot = 0;
            $max_pot = 0;
            $i = 6;
            $value = $limitedSkillsKeeper[$i]['value'];
            $value_ori = $limitedSkillsKeeper[$i]['value_ori'];
            switch ($limitedSkillsKeeper[$i]['type']) {
                case 'min_max':
                case 'actual_estimation':
                case 'actual':
                    $min_pot += $value_ori;
                    $max_pot += $value;
                    break;
                case 'rating':
                    $min_pot += $value_ori;
                    $max_pot += $value;
                    break;
                case 'max':
                    $min_pot += $value_ori;
                    $max_pot += $value;
                    break;
                case 'unknown':
                    $min_pot += 2;
                    $max_pot += $value;
                    break;
            }
            $i = 8;
            $value = $limitedSkillsKeeper[$i]['value'];
            $value_ori = $limitedSkillsKeeper[$i]['value_ori'];
            $term = 0.5 * min(1, $max_pot / 7.9);
            switch ($limitedSkillsKeeper[$i]['type']) {
                case 'min_max':
                case 'actual_estimation':
                case 'actual':
                    $min_pot += $term * min(6.9, $value_ori);
                    $max_pot += $term * min(6.9, $value);
                    break;
                case 'rating':
                    $min_pot += $term * min(6.9, $value_ori);
                    $max_pot += $term * min(6.9, $value);
                    break;
                case 'max':
                    $min_pot += $term * min(6.9, $value_ori);
                    $max_pot += $term * min(6.9, $value);
                    break;
                case 'unknown':
                    $min_pot += 2;
                    $max_pot += $term * min(6.9, $value);
                    break;
            }
            $min_pot_keeper = $min_pot;
            $max_pot_keeper = $max_pot;
            #endregion
        }
        $return = array(
            "status" => $status,
            "min_pot_field" => (
                /* if the max is higher than the possible max (100%), then we have to cut it to 100%
                 * but the distance, between the min_pot and max_pot should stay on the same level
                 * and this should also be the case if only the max_pot is higher than the possible max
                 */
            self::SCALA_FIELDER_MAX < $max_pot_field ?
                self::SCALA_FIELDER_MAX / ($max_pot_field / $min_pot_field) :
                $min_pot_field
            ) * $age_factor,
            "max_pot_field" => min(self::SCALA_FIELDER_MAX, $max_pot_field) * $age_factor,
            "min_pot_keeper" => (
                // same as above
            self:: SCALA_KEEPER_MAX < $max_pot_keeper ?
                self::SCALA_KEEPER_MAX / ($max_pot_keeper / $min_pot_keeper) :
                $min_pot_keeper
            ) * $age_factor,
            "max_pot_keeper" => min(self::SCALA_KEEPER_MAX, $max_pot_keeper) * $age_factor
        );
        return $return;
    }

}