<?php
/**
 * Jenkins Syntax Plugin: display and trigger Jenkins job inside Dokuwiki
 *
 * @author Algorys
 */

if (!defined('DOKU_INC')) die();
require 'jenkinsapi/jenkins.php';

class syntax_plugin_jenkins extends DokuWiki_Syntax_Plugin {

    public function getType() {
        return 'substition';
    }

    public function getPType() {
        return 'normal';
    }
    // Keep syntax inside plugin
    function getAllowedTypes() {
        return array('container', 'baseonly', 'substition','protected','disabled','formatting','paragraphs');
    }

    public function getSort() {
        return 199;
    }

    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<jenkins[^>]*/>', $mode, 'plugin_jenkins');
    }

    // Dokuwiki Handler
    function handle($match, $state, $pos, Doku_Handler $handler) {
        switch($state){
            case DOKU_LEXER_SPECIAL :
                $data = array(
                    'state' => $state,
                    'job' => null,
                    'build' => null,
                    'artifacts' => false,
                    'artifacts_preview' => false,
                );

                // Jenkins Job
                preg_match("/job *= *(['\"])(.*?)\\1/", $match, $job);
                if (count($job) != 0) {
                    $data['job'] = $job[2];
                }
                // Jenkins Build
                preg_match("/build *= *(['\"])(\\d+|last)\\1/", $match, $build_nb);
                if (count($build_nb) !== 0) {
                    $data['build'] = $build_nb[2];
                   
                    // List Artifacts?
                    preg_match("/artifacts *= *(['\"])(list|list_preview)\\1/", $match, $artifacts);
                    if (count($artifacts) != 0) {
                        $data['artifacts'] = true;
                        
                        // Render artifact previews for images?
                        switch ($artifacts[2]) {
                            case 'list_preview':
                                $data['artifacts_preview'] = true;
                                break;
                            case 'list':
                            default:
                                $data['artifacts_preview'] = false;
                                break;
                        }
                    }
                }

                return $data;
            case DOKU_LEXER_UNMATCHED :
                return array('state'=>$state, 'text'=>$match);
            default:
                return array('state'=>$state, 'bytepos_end' => $pos + strlen($match));
        }
    }

    // Dokuwiki Renderer
    function render($mode, Doku_Renderer $renderer, $data) {
        if($mode != 'xhtml') return false;

        $renderer->info['cache'] = false;
        switch($data['state']) {
            case DOKU_LEXER_SPECIAL:
                $this->rendererJenkins($renderer, $data);
            case DOKU_LEXER_EXIT:
            case DOKU_LEXER_ENTER:
            case DOKU_LEXER_UNMATCHED:
                $renderer->doc .= $renderer->_xmlEntities($data['text']);
                break;
        }
        return true;
    }

    function rendererJenkins($renderer, $data) {
        $preview_formats = array('.png', '.jpg', '.gif', '.svg');

        if ($data['job'] === null) {
            $this->renderErrorRequest($renderer, $this->getLang('jenkins.error.no_job'));
            return;
        }
        if ($data['build'] === null) {
            $this->renderErrorRequest($renderer, $this->getLang('jenkins.error.no_build'));
            return;
        }
        
        // Create jenkins API client
        $jenkins = new DokuwikiJenkins(
            $this->getConf('jenkins.url'),
            $this->getConf('jenkins.user'),
            $this->getConf('jenkins.token')
        );
        
        // Send request
        $build = $jenkins->requestBuild($data['job'], $data['build']);
        if ($build === null) {
            $this->renderErrorRequest($renderer, $this->getLang('jenkins.error.req_failed'));
            return;
        }

        // RENDERER
        // outer wrapper div
        $renderer->doc .= '<div class="jenkins-wrapper">';

        // === HEADER ===
        $renderer->doc .= '<div class="jenkins-head">';
        // header / status icon
        $build_icon = $this->getBuildIcon($build->result);
        if ($build_icon !== null) {
            $renderer->doc .= '<span class="jenkins-icon">';
            $renderer->doc .= '<img class="jenkins-icon" '
                . 'src="/dokuwiki/lib/plugins/jenkins/images/'. $build_icon . '" '
                . 'title="' . $build->result . '" alt="' . $build->result . '" />';
            $renderer->doc .= '</span>';
        }
        // header / job & build name
        $renderer->doc .= '<a href="' . $build->url . '">';
        $renderer->doc .= '<strong>' . $build->display_name . '</strong>';
        if ($data['build'] === 'last') {
            $renderer->doc .= ' (latest build)';
        }
        $renderer->doc .= '</a>';
        // header / jenkins logo
        $renderer->doc .= '<span class="jenkins-head-right">';
        $renderer->doc .= '<img class="jenkins-icon" src="/dokuwiki/lib/plugins/jenkins/images/jenkins.svg" alt="Jenkins Logo" />';
        $renderer->doc .= '</span>';
        // close header
        $renderer->doc .= '</div>';

        $renderer->doc .= '<hr />';

        // === JOB DETAILS ===
        // timestamp & duration
        $renderer->doc .= '<p>';
        $renderer->doc .= '<strong>' . $this->getLang('jenkins.timestamp') . ':</strong> ';
        $renderer->doc .= dformat(intdiv($build->timestamp, 1000));
        $renderer->doc .= ' <strong>' . $this->getLang('jenkins.duration') . ':</strong> ';
        $renderer->doc .= $build->durationPretty();
        $renderer->doc .= '</p>';

        // description
        if ($build->description !== null) {
            $renderer->doc .= '<p><strong>' . $this->getLang('jenkins.description') . ':</strong><br/>';
            $renderer->doc .= htmlentities($build->description);
            $renderer->doc .= '</p>';
        }

        // git references
        if (count($build->code_refs) > 0) {
            $renderer->doc .= '<p style="margin-bottom:0"><strong>' . $this->getLang('jenkins.code_refs') . ':</strong></p>';
            $renderer->doc .= '<ul>';
            foreach ($build->code_refs as &$code_ref) {
                $renderer->doc .= '<li><div class="li">';
                $renderer->doc .= '<a href="' . $code_ref['url'] . '">';
                $renderer->doc .= $code_ref['url'];
                $renderer->doc .= '</a>';
                $renderer->doc .= ', branch <code>' . $code_ref['branch'] . '</code>';
                $renderer->doc .= ', commit <code>' . substr($code_ref['commit'], 0, 8) . '</code>';
                $renderer->doc .= '</div></li>';
            }
            $renderer->doc .= '</ul>';
        }

        // === ARTIFACTS ===
        if (($data['artifacts'] === true) && (count($build->artifacts) > 0)) {
            $renderer->doc .= '<hr />';
            $renderer->doc .= '<p style="margin-bottom:0">';
            $renderer->doc .= '<strong>' . $this->getLang('jenkins.artifacts') . '</strong> (';
            $renderer->doc .= '<a href="' . $build->artifactsZipUrl() . '">zip</a>';
            $renderer->doc .= '):';
            $renderer->doc .= '</p>';
            $renderer->doc .= '<ul>';
            foreach ($build->artifacts as &$artifact) {
                $renderer->doc .= '<li><div class="li">';
                $renderer->doc .= '<a href="' . $artifact['url'] . '">' . $artifact['filename'] . '</a>';
                // TODO: Figure out how to set cross-origin headers to make this possible
                // if (
                //     ($data['artifacts_preview'] === true)
                //     && (in_array(strtolower(substr($artifact['filename'], -4)), $preview_formats))
                // ) {
                //     $renderer->doc .= '<br />';
                //     $renderer->doc .= '<a href="' . $artifact['url'] . '">';
                //     $renderer->doc .= '<img class="jenkins-artifact-preview jenkins-artifact-preview-scaled" alt="Artifact Preview" src="' . $artifact['url'] . '" />';
                //     $renderer->doc .= '</a>';
                // }
            }
            $renderer->doc .= '</div></li>';
        }

        // close outer div
        $renderer->doc .= '</div>'; // jenkins-wrapper
    }

    function renderErrorRequest($renderer, $error_msg) {
        // outer wrapper div
        $renderer->doc .= '<div class="jenkins-wrapper">';
        $renderer->doc .= '<div class="jenkins-head">';
        // header / job & build name
        $renderer->doc .= '<strong>Error</strong>';
        // header / jenkins logo
        $renderer->doc .= '<span class="jenkins-head-right">';
        $renderer->doc .= '<img class="jenkins-icon" src="/dokuwiki/lib/plugins/jenkins/images/jenkins.svg" alt="Jenkins Logo" />';
        $renderer->doc .= '</span>';
        $renderer->doc .= '</div>';
        $renderer->doc .= '<p>';
        $renderer->doc .= $this->getLang('jenkins.error') . " " . $error_msg;
        $renderer->doc .= '</p>';
        // close header
        // close outer div
        $renderer->doc .= '</div>'; // jenkins-wrapper
    }

    function getBuildIcon($result) {
        switch($result) {
            case 'SUCCESS':
                return 'success.svg';
            case 'ABORTED':
                return 'aborted.svg';
            case 'FAILURE':
                return 'failed.svg';
            default:
                return null;
        }
    }
}
