<?php
$d=0;
for($match=1;$match<=49;$match++)
{
    //Initialise all global arrays to null
    $phrase_index=array();
    $sentence_index=array();
    $commentary1=array();
    $commentary2=array();
    $mentions=array();

    process_match($match);
}

function process_match($match_id)
{
    global $commentary1;
    global $commentary2;
    global $mentions;
    echo "Processing match $match_id\n";
    $commentary1=read_inning_commentary($match_id,1);//read first inning
    $commentary2=read_inning_commentary($match_id,2);//read the second inning
    $cx1=count($commentary1);
    $cx2=count($commentary2);
    debug("cx1=$cx1 cx2=$cx2\n");

    read_report($match_id);
    //echo "Printing mentions for $match_id\n";
    //print_r($mentions);

    create_mention_file($mentions,$match_id);
    create_tagged_file($mentions,$match_id);
    echo "Completed processing match $match_id\n";
}

function create_mention_file($mentions,$match_id)
{
    global $mentions;
    $file_content='';
    debug( "Creating mention_file $match_id");
    //print_r($mentions);
    foreach($mentions as $id=>$mention)
    {
        $mid=$id+1;
        //echo "id=$id mention=$mention\n";
        $balls=trim($mention['balls']);
        $file_content.="m$mid\t$balls\n";
    }
    $filename="mentionLinks$match_id.html";
    file_put_contents($filename,$file_content);
}
function create_tagged_file($mentions,$match_id)
{
    echo "Printing mentions in create_tagged_file\n";
    //print_r($mentions);
    debug( "Creating tagged file $match_id\n");
    $filename="../data/match".$match_id."report.html";
    $content=file_get_contents($filename);
    $length=strlen($content);
    foreach($mentions as $id=>$mention)
    {
        $mid=$id+1;
        //echo "id=$id mention=$mention\n";
        $phrase=$mention['phrase'];
        $to_be_replaced="<m$mid>$phrase</m$mid>";
        debug( "Replacing $phrase with $to_be_replaced\n");
        $content=str_ireplace($phrase,$to_be_replaced,$content);
    }
    $target_filename="match$match_id"."reportTagged.html";
    echo "Writing to file $target_filename\n";
    file_put_contents($target_filename,$content);
}
function process_phrases($max_paragraphs)
{
    global $phrase_index;
    foreach($phrase_index as $id=>$row)
    {
        process_rules_on_phrase($id,$row,$max_paragraphs);
    }
}

function process_rules_on_phrase($id,$phrase)
{
    global $phrase_index;
    extract($phrase);
    if($paragraph_num==1) return ;//ignore the first paragraph
    check_off_rule($id,$phrase);
    check_cardinal_overs_rule($id,$phrase);
    check_score_rule($id,$phrase);
}
function check_score_rule($id,$phrase)
{
    global $phrase_index;
    global $mentions;
    extract($phrase);
    debug( "Entered check_score_rule\n");
    if(strstr($phrase_text," for "))
    {
        debug( "check_score_rule: for present\n");
        //split the phrase of type something off with space as delimiter and check the index of off
        $words=explode(" ",$phrase_text);
        $idx=array_search("for",$words);
        debug( "idx=$idx\n");
        if($idx==0) return;//which means the first word is for, in that case this rule can be aborted
        //check for the cardinality of the word just before the word over
        //print_r($words);
        for($i=0;$i<$idx;$i++)
        {
            $cardinal_word.=$words[$i];//to handle cases like thirty seven etc.
        }
        debug( "Printing phrase $phrase_text\n");
        $before_card=has_cardinality($cardinal_word);
        debug( "score_rule::card=$before_card\n");
        if(!$before_card)
        {
            debug( "before for, cardinality not found. Aborting rule\n");
            return;
        }
        for($i=$idx;$i<count($words);$i++)
        {
            $after_word.=$words[$i];//to handle cases like thirty seven etc.
        }
        $after_card=has_cardinality($after_word);
        if(!$after_card)
        {
            debug( "after for, cardinality not found. Aborting rule\n");
            return;
        }

        //Mark this as a score rule
        $runs=get_cardinality($cardinal_word);
        $wickets=get_cardinality($after_word);
        echo "MATCH::SCORE RULE DETECTED with runs=$runs wickets=$wickets\n";
        $ball_row=find_ball_at_score($runs,$wickets);
        if($ball_row)
        {
            //print_r($ball_row);
            $ball=$ball_row['ball'];
            $innings=$ball_row['innings'];
            $mention_ball="$innings:$ball";
            $mention=array("phrase"=>$phrase_text,"balls"=>$mention_ball);
            $mentions[]=$mention;
        }


    }
    else
    {
        debug( "Word for not present. Returning\n");
        return;
    }

}
function check_off_rule($id,$phrase)
{
    global $phrase_index;
    extract($phrase);
    debug( "Entered check_off_rule\n");

    //see if the word off exists
    if(strstr($phrase_text," off"))
    {
        //split the phrase of type something off with space as delimiter and check the index of off
        $words=explode(" ",$phrase_text);
        $idx=array_search("off",$words);
        //debug( "idx=$idx\n";
        if($idx==0) return;//which means the first word is off, in that case this rule can be aborted
        //debug( "printing phrase\n";
        //print_r($phrase);
        //print_r($words);
        //now check for cardinalities in two sub arrays separated by off. For this pass the array indices to the 
        //checking function

        $left_cardinality=check_for_cardinality($words,0,$idx);
        if(!$left_cardinality)
        {
            debug( "No left cardinality. Aborting rule\n");
            return;
        }
        $left_qualifier=check_for_qualifier($words,0,$idx);
        if(!$left_qualifier)
        {
            //if the left of the off is a cardinal then assume the cardinality as runs
            if(has_cardinality($words[$idx-1]))
                $left_qualifier=1;
        }
        if(!$left_qualifier)
        {
            debug( "No left qualifier. Aborting rule\n");
            return;
        }

        $right_cardinality=check_for_cardinality($words,$idx+1,count($words));
        if(!$right_cardinality)
        {
            debug( "No right cardinality. Aborting rule\n");
            return;
        }
        $right_qualifier=check_for_qualifier($words,$idx+1,count($words));
        if(!$right_qualifier)
        {
            if(has_cardinality($words[$idx+1]))
                $right_qualifier=3;//default wickets
        }
        if(!$right_qualifier)
        {
            debug( "No right qualifier. Aborting rule\n");
            return;
        }

        debug( "LQ=$left_qualifier RQ=$right_qualifier\n");
        debug( "LC=$left_cardinality RC=$right_cardinality\n");
        echo "MATCH: OFF RULE DETECTED with LC=$left_cardinality and RC=$right_cardinality \n";

    }
    else
    {
        debug( "Word off not present. Returning\n");
        return;
    }
}
function check_cardinal_overs_rule($id,$phrase)
{
    global $phrase_index;
    global $mentions;
    extract($phrase);
    debug( "Entered cardinal_overs_rule\n");
    if(strstr($phrase_text,"over"))
    {
        //split the phrase of type something off with space as delimiter and check the index of off
        $words=explode(" ",$phrase_text);
        $idx=array_search("over",$words);
        debug( "idx=$idx\n");
        if($idx==0) return;//which means the first word is off, in that case this rule can be aborted
        //check for the cardinality of the word just before the word over
        //print_r($words);
        for($i=0;$i<$idx;$i++)
        {
            $cardinal_word.=$words[$i];//to handle cases like thirty seven etc.
        }
        debug( "Printing phrase $phrase_text\n");
        $card=has_cardinality($cardinal_word);
        debug( "Cardinal_overs_rule::card=$card\n");
        if(!$card)
        {
            debug( "No cardinal number before over. Hence aborting rule\n");
            return;
        }
        $overs=get_cardinality($cardinal_word);
        debug( "MATCH:CARDINAL OVERS DETECTED with overs=$overs\n");
        $inning=$phrase['approx_inning'];
        $mention_ball="$inning:$overs.1-$overs.6";
        $mention=array("phrase"=>$phrase_text,"balls"=>$mention_ball);
        //print_r($mention);
        $mentions[]=$mention;

    }
    else
    {
        debug( "Word over not present. Returning\n");
        return;
    }

}

function check_for_cardinality($words,$start,$end)
{
    for($i=$start;$i<$end;$i++)
    {
        $word=$words[$i];
        //debug( "Checking cardinality for $word\n";
        $card=has_cardinality($words[$i]);
        //debug( "Cardx=$card\n";
        if($card) return $card;
    }
}
function check_for_qualifier($words,$start,$end)
{
    for($i=$start;$i<$end;$i++)
    {
        $card=get_qualifier($words[$i]);
        if($card) return $card;
    }
}


function get_qualifier($word)
{
    $word=trim(strtolower($word));
    if(strstr($word,"over"))
        return 1;
    if(strstr($word,"runs"))
        return 2;
    if(strstr($word,"ball"))
        return 3;
    return 0;
}



function add_approx_inning($num_paragraphs)
{
    global $phrase_index;
    foreach($phrase_index as $id=>$row)
    {
        $paragraph_num=$row['paragraph_num'];
        $pos = $paragraph_num/$num_paragraphs;
        debug( "Pos=$pos\n");
        if($pos > 0.5)
            $inning=2;
        else
            $inning=1;
        $row['approx_inning']=$inning;
        $phrase_index[$id]=$row;
    }
}

function read_report($match_id)
{
    global $sentence_index;
    global $phrase_index;

    $phrase_index=array();
    $sentence_index=array();
    //open the right file
    $filename="../data/match".$match_id."report.html";
    $content=file_get_contents($filename);
    $length=strlen($content);
    debug( "Content length=$length\n");
    $docObj = new DOMDocument();
    @$docObj->loadHTML( $content );
    $xpath = new DOMXPath( $docObj );
    $nodes=$xpath->query("//p[@class='news-body']");
    //printNodeList($nodes);
    $num_paragraphs=0;
    foreach( $nodes as $node ) 
    {
        $value=trim($node->nodeValue);
        if($value=="")
        {
            //debug( "Paragraph separator\n";
            $num_paragraphs++;
        }
        else
        {
            debug("Printing paragraph $num_paragraphs\n$value");
            /*Skip anything in brackets as it is some other comment not related to this match*/
            $start = '\(';
            $end  = '\)';
            $value = preg_replace('#('.$start.')(.*)('.$end.')#si', '', $value);
            if(strstr($value,"_isStory")) continue;


            $paragraphs[]=$value;
        }
    }
    $last_phrase_count=0;
    debug("Number of paragraphs=$num_paragraphs\n");
    foreach($paragraphs as $paragraph_id=>$paragraph)
    {
        debug( "paragraph=$paragraph_id paragraph=$paragraph\n");
        process_paragraph($paragraph_id,$paragraph,$num_paragraphs);
    }
    //print_r($sentence_index);
    process_sentences($num_paragraphs);
    debug( "beginning to add approximate inning calculation");
    add_approx_inning($num_paragraphs);
    process_phrases($num_paragraphs);

}
function process_sentences($num_paragraph)
{
    global $sentence_index;
    global $phrase_index;
    debug("Entered process_sentences with $num_paragraph\n");
    //print_r($sentence_index);
    foreach($sentence_index as $id=>$sentence)
    {
        $phrases_array=multi_explode(array(','," - ", " and ", " but "),$sentence['sentence_text']);
        $i=0;
        foreach($phrases_array as $phrase_text)
        {
            $row=array();

            $row['phrase_text']=trim(strtolower($phrase_text));
            $row['phrase_num']=$i;
            $row['paragraph_num']=$sentence['paragraph_num'];
            $row['phrase_sentence']=$sentence['sentence_text'];
            $i++;
            if($phrase_text)
                $phrase_index[]=$row;
        }
    }
    $phrase_count=count($phrase_index);
    debug("Created $phrase_count phrases\n");
}

function process_paragraph($paragraph_id,$paragraph_text,$num_paragraph)
{
    global $sentence_index;
    $sentences_array=explode('.',$paragraph_text);
    $i=0;
    foreach($sentences_array as $sentence_text)
    {
        $row=array();

        $row['sentence_text']=trim(strtolower($sentence_text));
        $row['sentence_num']=$i;
        $row['paragraph_num']=$paragraph_id;
        $i++;
        if($sentence_text)
            $sentence_index[]=$row;
    }
}

function multi_explode ($delimiters,$string) {

    $ready = str_replace($delimiters, $delimiters[0], $string);
    $launch = explode($delimiters[0], $ready);
    return  $launch;
}


function read_inning_commentary($match_id,$inning_id)
{
    //open the right file
    $filename="../data/match".$match_id."innings".$inning_id."Commentary.html";
    $content=file_get_contents($filename);
    $length=strlen($content);
    debug( "Content length=$length\n");
    $docObj = new DOMDocument();
    @$docObj->loadHTML( $content );
    $xpath = new DOMXPath( $docObj );

    $nodes=$xpath->query("//p[@class='commsText']");
    //printNodeList($nodes);
    $skip_count=get_skip_count($nodes);
    debug( "Skip_count=$skip_count\n");
    return index_ball_by_ball_commentary($nodes,$skip_count,$match_id,$inning_id);
}
function index_ball_by_ball_commentary($nodes,$skip_count,$match,$innings)
{
    global $scores;
    $found=false;
    $ball=1;
    $flag_ball=true;
    $flag_commentary=false;
    $prev_ball_rep=false;
    foreach( $nodes as $node ) 
    {
        if($ball > 300) break;
        if($skip_count)
        {
            $skip_count--;
            continue;
        }
        $value=trim($node->nodeValue);
        if($flag_ball)
        {
            $overs=floor(($ball-1)/6);
            $current_ball_in_over=1+($ball-1)%6;
            $prev_ball_rep=$ball_rep;
            $ball_rep="$overs.$current_ball_in_over";
            //check for the right ball
            if($ball_rep==$value||$prev_ball_rep==$value)
            {
                debug( "ball $value\n");
            }
            else
            {
                debug( "ball=$ball expected ball=$ball_rep received=$value. Hence continuing...\n");
                continue;
            }


        }
        if($flag_commentary)
        {
            //parse the $value which is of the form
            //$x to $y,
            $arr=explode(",",$value);
            $whos=$arr[0];
            $result=$arr[1];
            $commentary=$arr[2];

            //parse the who
            $persons=explode("to",$whos);
            $bowler=$persons[0];
            $batsman=$persons[1];
            //trim everyone
            $bowler=trim($bowler);
            $batsman=trim($batsman);
            $result=trim($result);
            $commentary=trim($commentary);
            $runs=get_translated_runs($result);
            $cumulative_runs+=$runs;
            $is_out=is_out($result);
            $cumulative_outs+=$is_out;
            $runs_from_boundary=get_boundary_runs($result);

            $row=array(
                "match"=>$match,
                "innings"=>$innings,
                "ball"=>$ball_rep,
                "bowler"=>$bowler,
                "batsman"=>$batsman,
                "result"=>$result,
                "commentary"=>$commentary,
                "runs"=>$runs,
                "cumulative_runs"=>$cumulative_runs,
                "cumulative_outs"=>$cumulative_outs,
                "is_out"=>$is_out,
                "runs_from_boundary"=>$runs_from_boundary
            );
            $scores[$result]=$result;
            $index[$ball]=$row;
            $ball++;
            //print_r($row);
        }
        $flag_ball=!$flag_ball;
        $flag_commentary=!$flag_commentary;
    }
    return $index;
}







function printNodeList( DOMNodeList $nodeList ) 
{
    foreach( $nodeList as $node ) 
    {
        echo '<', $node->tagName, '> ', $node->nodeValue, ' <', $node->tagName, '> ', "\n";
    }
}
function get_skip_count($nodes)
{
    $found=false;
    $skip_count=0;
    foreach( $nodes as $node ) 
    {
        $val=trim($node->nodeValue);
        if($val=="0.1") 
            return $skip_count;
        $skip_count++;
    }

}


function get_translated_runs($result)
{
    $result=trim($result);
    $result=strtolower($result);
    $result=str_replace("(","",$result);
    $result=str_replace(")","",$result);
    switch($result)
    {
    case "four": return 4;
    case "2 runs": return 2;
    case "no run": return 0;
    case "1 wide": return 1;
    case "1 run": return 1;
    case "1 leg bye": return 1;
    case "out": return 0;
    case "3 runs": return 3;
    case "six": return 6;
    case "3 wides": return 3;
    case "no ball 1 run": return 0;
    case "1 bye": return 1;
    case "4 leg byes": return 4;
    case "5 wides": return 5;
    case "1 no ball": return 0;
    case "4 byes": return 4;
    case "2 byes": return 2;
    case "2 wides": return 2;
    case "2 leg byes": return 2;
    case "5 runs": return 5;
    case "no ball four": return 4;
    case "5 no balls": return 0;
    case "2 no balls": return 0;
    case "4 runs": return 4;
    case "4 wides": return 4;
    case "no ball 2 runs": return 2;
    case "no ball 3 runs": return 3;
    case "3 leg byes": return 3;
    }
    //debug( "New case $result";exit();
    return "UNKNOWN";
}

function is_out($result)
{
    $result=trim($result);
    $result=strtolower($result);
    $result=str_replace("(","",$result);
    $result=str_replace(")","",$result);
    if($result=="out")
        return 1;
    return 0;
}
function get_boundary_runs($result)
{
    $result=trim($result);
    $result=strtolower($result);
    $result=str_replace("(","",$result);
    $result=str_replace(")","",$result);
    switch($result)
    {
    case "four": return 4;
    case "six": return 6;
    }
    //debug( "New case $result";exit();
    return 0;
}


function has_cardinality($string)
{
    global $cardinality_indicator;
    $total=0;
    $string=trim($string);
    $string=strtolower($string);
    //debug( "entered has_cardinality with $string\n";
    for($i=9999;$i>0;$i--)
    {
        if("$i"==$string)
        { 
            //debug( "i=$i string=$string\n";
            return $i;
        }
        if(strstr($string,"$i")) return $i;
    }
    if(strstr($string,"0")) return 1;
    //debug( "string=$string\n";

    if(strstr($string,"50")||strstr($string,"fifty")||strstr($string,"50th"))
        $total+=50;
    if(strstr($string,"40")||strstr($string,"forty")||strstr($string,"40th"))
        $total+=40;
    if(strstr($string,"30")||strstr($string,"thirty")||strstr($string,"30th"))
        $total+=30;
    if(strstr($string,"20")||strstr($string,"twenty")||strstr($string,"20th"))
        $total+=20;
    if(strstr($string,"10")||strstr($string,"tenth")||strstr($string,"10th"))
        return 10;
    if(strstr($string,"9")||strstr($string,"ninth")||strstr($string,"9th"))
    {
        $total+=9;
        return $total;
    }
    if(strstr($string,"8")||strstr($string,"eighth")||strstr($string,"8th")||strstr($string,"eight"))
    {
        $total+=8;
        return $total;
    }
    if(strstr($string,"7")||strstr($string,"seventh")||strstr($string,"7th")||strstr($string,"seven"))
    {
        $total+=7;
        return $total;
    }
    if(strstr($string,"6")||strstr($string,"sixth")||strstr($string,"6th")||strstr($string,"six"))
    {
        $total+=6;
        return $total;
    }
    if(strstr($string,"5")||strstr($string,"fifth")||strstr($string,"5th")||strstr($string,"five"))
    {
        $total+=5;
        return $total;
    }
    if(strstr($string,"4")||strstr($string,"four")||strstr($string,"4th")||strstr($string,"four"))
    {
        $total+=4;
        return $total;
    }
    if(strstr($string,"3")||strstr($string,"third")||strstr($string,"3rd")||strstr($string,"three"))
    {
        $total+=3;
        return $total;
    }
    if(strstr($string,"2")||strstr($string,"second")||strstr($string,"2nd")||strstr($string,"two"))
    {
        $total+=2;
        return $total;
    }
    if(strstr($string,"1")||strstr($string,"first")||strstr($string,"1st")||strstr($string,"one"))
    {
        $total+=1;
        return $total;
    }
    return $total;

}
function get_cardinality($string)
{
    global $cardinality_indicator;
    $total=0;
    $string=trim($string);
    $string=strtolower($string);
    //debug( "entered has_cardinality with $string\n";
    for($i=9999;$i>0;$i--)
    {
        if("$i"==$string)
        { 
            //debug( "i=$i string=$string\n";
            return $i;
        }
        if(strstr($string,"$i")) return $i;
    }
    debug( "string=$string\n");

    if(strstr($string,"50")||strstr($string,"fifty")||strstr($string,"50th"))
        $total+=50;
    if(strstr($string,"40")||strstr($string,"forty")||strstr($string,"40th"))
        $total+=40;
    if(strstr($string,"30")||strstr($string,"thirty")||strstr($string,"30th"))
        $total+=30;
    if(strstr($string,"20")||strstr($string,"twenty")||strstr($string,"20th"))
        $total+=20;
    if(strstr($string,"10")||strstr($string,"tenth")||strstr($string,"10th"))
        return 10;
    if(strstr($string,"9")||strstr($string,"ninth")||strstr($string,"9th"))
    {
        $total+=9;
        return $total;
    }
    if(strstr($string,"8")||strstr($string,"eighth")||strstr($string,"8th")||strstr($string,"eight"))
    {
        $total+=8;
        return $total;
    }
    if(strstr($string,"7")||strstr($string,"seventh")||strstr($string,"7th")||strstr($string,"seven"))
    {
        $total+=7;
        return $total;
    }
    if(strstr($string,"6")||strstr($string,"sixth")||strstr($string,"6th")||strstr($string,"six"))
    {
        $total+=6;
        return $total;
    }
    if(strstr($string,"5")||strstr($string,"fifth")||strstr($string,"5th")||strstr($string,"five"))
    {
        $total+=5;
        return $total;
    }
    if(strstr($string,"4")||strstr($string,"four")||strstr($string,"4th")||strstr($string,"four"))
    {
        $total+=4;
        return $total;
    }
    if(strstr($string,"3")||strstr($string,"third")||strstr($string,"3rd")||strstr($string,"three"))
    {
        $total+=3;
        return $total;
    }
    if(strstr($string,"2")||strstr($string,"second")||strstr($string,"2nd")||strstr($string,"two"))
    {
        $total+=2;
        return $total;
    }
    if(strstr($string,"1")||strstr($string,"first")||strstr($string,"1st")||strstr($string,"one"))
    {
        $total+=1;
        return $total;
    }
    return $total;

}

function debug($str)
{
    global $d;
    if($d) echo $str;
}


function find_ball_at_score($runs,$wickets)
{
    global $commentary1,$commentary2;
    debug("Entered find_ball_at_score with runs=$runs wickets=$wickets");
    $cx=count($commentary1);
    debug("Commentary1 has $cx balls\n");
    $cx=count($commentary2);
    debug("Commentary2 has $cx balls\n");
    for($i=0;$i<count($commentary1);$i++)
    {
        $row=$commentary1[$i];
        $cumulative_runs=$row['cumulative_runs'];
        $cumulative_outs=$row['cumulative_outs'];
        //print_r($row);
        if($runs==$cumulative_runs && $wickets==$cumulative_outs)
        {
            debug("ball found at inning $innings ball=$ball\n");
            return $row;
        }
    }
    for($i=0;$i<count($commentary2);$i++)
    {
        $row=$commentary2[$i];
        $cumulative_runs=$row['cumulative_runs'];
        $cumulative_outs=$row['cumulative_outs'];
        if($runs==$cumulative_runs && $wickets==$cumulative_outs)
        {
            debug("ball found at inning $innings ball=$ball\n");
            return $row;
        }
    }

    //check if it is overs
    return null;
}
