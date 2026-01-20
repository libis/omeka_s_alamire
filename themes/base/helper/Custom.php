<?php

namespace OmekaTheme\Helper;

use Laminas\View\Helper\AbstractHelper;

class Custom extends AbstractHelper
{
    public function renderTree($tree, $auth, $helper, $depth = 0)
    {
        foreach ($tree as $id => $item) {
            $hasChildren = !empty($item['children']);
            $isTopLevel = ($depth === 0);
            $rowId = "row-$id";
            $childTbodyId = "children-of-$id";
            echo "<tr id='$rowId'>";
            $this->renderComposition($item['value'], $auth, $depth);
            // Right-aligned toggle in last cell
            echo "<td style='text-align: right;'>";
            if ($isTopLevel && $hasChildren) {
                echo "<button aria-expanded='false' alt='toggle' class='button is-small is-primary toggle-btn' data-target='$childTbodyId'>
                <span class='icon is-small'>
                <i class='fas fa-chevron-down'></i>
                </span>
                </button>";
            }
            echo "</td>";
            if ( $isTopLevel) {
                //echo '<a href="#" class="expand expand-icon"><i class="fa fa-chevron-down"></i></a>';
                //echo "<div class='hidden-content' style='display:block;'>"; // You can toggle visibility logic
                 echo "<tbody class='$childTbodyId' class='child-tbody' style='display:none'>";
            }
                $this->renderTree($item['children'], $auth, $helper, $depth + 1);
            if ( $isTopLevel) {    
                echo "</tbody>";
                //echo "</div>";
            }
            
            echo "</tr>";
        }
    }

    public function renderComposition($irel, $auth, $depth)
    {
        //print_r($irel);

        $label = "";
        $seq = "";
        //page
        if (trim($irel[5]) == "fol.") {
            $label = trim($irel[6]);
            $label = str_replace("page:", "p.", $label);
        } else {
            //fol
            $label = trim($irel[5]);
        }
        if (trim($irel[4])) {
            $seq = "<span style='width:27px;margin-right:2px;display:inline-block;'>" . trim($irel[4]) . ". </span>";
        }

        $query = 'property[0][joiner]=and&property[0][property]=216&property[0][type]=eq&property[0][text]=' . trim($irel[2]) . '&item_set_id[]=&site_id=';
        parse_str($query, $query);

        $item = $this->getView()->api()->searchOne('items', $query);
        $item = $item->getContent();
        if ($item):
            $cites = $item->value('alamire:creator_display', array('all' => true));
            $sort_cites = array();
            foreach ($cites as $cite):
                if (str_contains($cite->asHtml(), "$$")):
                    $cite = explode(" $$ ", $cite->asHtml());
                    $comps = trim($cite[0]);
                    $comps = explode(" (", $comps);
                    if (!empty($comps[1])):
                        $type = $comps[1];
                        if ($type == "credited)"):
                            $comp = $comps[0];
                        elseif ($type == "stylistic)"):
                            $comp = "[" . $comps[0] . "]";
                        elseif ($type == "contested)"):
                            $comp = "?" . $comps[0];
                        else:
                            $comp = $comps[0];
                        endif;
                    else:
                        $comp = $comps[0];
                    endif;
                    $sort_cites[trim($cite[1])] = $comp;
                endif;
            endforeach;
            ksort($sort_cites);

            $composer = implode("; ", $sort_cites);

            if(isset($irel[9])):
                $irel[3] = "<span style='color:#a6361f'>".$irel[9]."</span> ".$irel[3];
            endif;    
            if($depth > 0):
                echo  "<td>" . $seq . $composer . '</td><td><span style="margin-left: ' . ($depth * 1.2) . 'rem;">' . $irel[3] . '</span></td>';
            else:   
                echo  "<td>" . $seq . $composer . '</td><td><a href="' . $item->url() . '"><span>' . $irel[3] . '</span></a></td>';
            endif;    
            echo "<td>";
            if (str_contains($irel[7], "http") && $auth):
                echo '<a target="_blank" style="font-style:italic;" href="' . str_replace(" link: ", "", $irel[7]) . '">' . $label . '</a></td>';
            else:
                echo $label;
            endif;
            echo "</td>";
        else:
            if(isset($irel[9])):
                $irel[3] = "<span style='color:#a6361f'>".$irel[9]."</span> ".$irel[3];
            endif;    
            //echo $irel[3]." ".$irel[4]."<br>";
            echo "<td></td><td><span style='margin-left: " . ($depth * 1.2) . "rem;'>" . $irel[3] . "</span></td>";
            echo "<td>";
            if (str_contains($irel[7], "http") && $auth):
                echo '<a target="_blank" style="font-style:italic;" href="' . str_replace(" link: ", "", $irel[7]) . '">' . $label . '</a>';
            else:
                echo $label;
                echo "</td>";
            endif;
        endif;
    }

    public function reverseName($name){
        $parts = explode(", ", $name);
        if (count($parts) == 2) {
            return $parts[1] . " " . $parts[0];
        }
        return $name;
    }
}