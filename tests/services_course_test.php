<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

use theme_snap\services\course;
use theme_snap\renderables\course_card;
use theme_snap\local;

/**
 * Test course card service.
 * @package   theme_snap
 * @author    gthomas2
 * @copyright Copyright (c) 2016 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class theme_snap_services_course_test extends \advanced_testcase {

    /**
     * @var stdClass
     */
    protected $user1;

    /**
     * @var array
     */
    protected $courses = [];

    /**
     * @var course
     */
    protected $courseservice;

    /**
     * Pre-requisites for tests.
     * @throws \coding_exception
     */
    public function setUp() {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $this->resetAfterTest();

        // Create 10 courses.
        for ($c = 0; $c < 10; $c++) {
            $this->courses[] = $this->getDataGenerator()->create_course();
        }

        $this->user1 = $this->getDataGenerator()->create_user();

        // Enrol user to all courses.
        $sturole = $DB->get_record('role', array('shortname' => 'student'));

        foreach ($this->courses as $course) {
            $this->getDataGenerator()->enrol_user($this->user1->id,
                $course->id,
                $sturole->id);
        }

        $this->courseservice = course::service();
    }

    public function test_service() {
        $testservice = course::service();
        $this->assertEquals($this->courseservice, $testservice);
        $this->assertTrue($testservice instanceof course);
    }

    public function test_favorited() {
        $service = $this->courseservice;
        $favorited = $service->favorited($this->courses[0], $this->user1->id);
        $this->assertFalse($favorited);

        $service->setfavorite($this->courses[0]->shortname, true, $this->user1->id);

        // Make sure marked as favorite.
        $favorited = $service->favorited($this->courses[0]->id, $this->user1->id);
        $this->assertTrue($favorited);
    }

    public function test_favorites() {
        $service = $this->courseservice;
        $service->setfavorite($this->courses[0]->shortname, true, $this->user1->id);
        $service->setfavorite($this->courses[1]->shortname, true, $this->user1->id);

        $favorites = $service->favorites($this->user1->id);
        $this->assertTrue(isset($favorites[$this->courses[0]->id]));
        $this->assertTrue(isset($favorites[$this->courses[1]->id]));
        $this->assertFalse(isset($favorites[$this->courses[2]->id]));
    }

    public function test_my_courses_split_by_favorites() {
        $service = $this->courseservice;
        $service->setfavorite($this->courses[0]->shortname, true, $this->user1->id);
        $service->setfavorite($this->courses[1]->shortname, true, $this->user1->id);

        $this->setUser($this->user1);
        list ($favorites, $notfavorites) = $service->my_courses_split_by_favorites();

        $expectedfavorites = [
            $this->courses[0]->id,
            $this->courses[1]->id
        ];

        $this->assertEquals($expectedfavorites, array_keys($favorites));
        $notfavoritecourses = array_slice($this->courses, 2);
        $expectednotfavorites = [];
        foreach ($notfavoritecourses as $course) {
            $expectednotfavorites[] = $course->id;
        }
        asort($notfavorites);
        $this->assertEquals($expectednotfavorites, array_keys($notfavorites));
    }

    public function test_setfavorite() {
        $returned = $this->courseservice->setfavorite($this->courses[0]->shortname, true, $this->user1->id);
        $this->assertTrue($returned);
    }

    public function test_coursebyshortname() {
        $expected = get_course($this->courses[0]->id);
        $actual = $this->courseservice->coursebyshortname($this->courses[0]->shortname);

        $this->assertEquals($expected, $actual);
    }

    public function test_cardbyshortname() {
        $card = $this->courseservice->cardbyshortname($this->courses[0]->shortname);
        $this->assertTrue($card instanceof course_card);
        $this->assertEquals($card->courseid, $this->courses[0]->id);
    }

    public function test_course_completion() {
        global $DB;

        $this->resetAfterTest();

        // Enable avaibility.
        // If not enabled all conditional fields will be ignored.
        set_config('enableavailability', 1);

        // Enable course completion.
        // If not enabled all completion settings will be ignored.
        set_config('enablecompletion', COMPLETION_ENABLED);

        $generator = $this->getDataGenerator();

        // Create course with completion tracking enabled.
        $course = $generator->create_course([
            'enablecompletion' => 1,
            'numsections' => 3
        ], ['createsections' => true]);
                
        // Enrol user to completion tracking course.
        $sturole = $DB->get_record('role', array('shortname' => 'student'));
        $generator->enrol_user($this->user1->id,
            $course->id,
            $sturole->id);

        // Create page with completion marked on view.
        $page1 = $generator->create_module('page', array('course' => $course->id, 'name' => 'page1 complete on view'),
            array('completion' => 2, 'completionview' => 1));
        $modinfo = get_fast_modinfo($course);
        $page1cm = $modinfo->get_cm($page1->cmid);

        // Create page restricted to only show when first page is viewed.
        $moduleinfo = (object)[];
        $moduleinfo->course = $course->id;
        $moduleinfo->name = 'page2 available after page1 viewed';
        $moduleinfo->availability = json_encode(\core_availability\tree::get_root_json(
            [\availability_completion\condition::get_json($page1->cmid, COMPLETION_COMPLETE)], '&'));
        $page2 = $generator->create_module('page', $moduleinfo);

        // Make section 2 restricted to only show when first page is viewed.
        $section = $modinfo->get_section_info(2);
        $sectionupdate =  [
            'id' => $section->id,
            'availability' => json_encode(\core_availability\tree::get_root_json(
                [\availability_completion\condition::get_json($page1->cmid, COMPLETION_COMPLETE)], '&'))
        ];
        $DB->update_record('course_sections', $sectionupdate);

        // Check user1 has expected unavailable section and mod.
        $this->setUser($this->user1);

        // Dump cache and reget modinfo.
        get_fast_modinfo($course, 0, true);
        $modinfo = get_fast_modinfo($course);

        $page2cm = $modinfo->get_cm($page2->cmid);
        list ($previouslyunavailablesections, $previouslyunavailablemods) = local::conditionally_unavailable_elements($course);
        $this->assertContains(2, $previouslyunavailablesections);
        $this->assertContains($page2cm->id, $previouslyunavailablemods);
        
        // View page1 to trigger completion
        $context = context_module::instance($page1->cmid);
        page_view($page1, $course, $page1cm, $context);
        $completion = new completion_info($course);
        $completiondata = $completion->get_data($page1cm);
        $this->assertEquals(COMPLETION_COMPLETE, $completiondata->completionstate);

        get_fast_modinfo($course, 0, true); // Reset modinfo.

        // Make sure that unavailable sections and mods no longer contain the ones requiring availabililty criteria
        // satisfying.
        list ($unavailablesections, $unavailablemods) = local::conditionally_unavailable_elements($course);
        $this->assertNotContains($page2cm->id, $unavailablemods);
        $this->assertNotContains(2, $unavailablesections);
                
        $result = $this->courseservice->course_completion($course->shortname,
            $previouslyunavailablesections,
            $previouslyunavailablemods);

        // Make sure that the second page module (which is now newly available) appears in the list of newly available
        // module html.
        $this->assertTrue(isset($result['newlyavailablemodhtml'][$page2->cmid]));

        // Make sure that the second section (which is now wnely available) appears in the list of newly available
        // section html.
        $this->assertTrue(isset($result['newlyavailablesectionhtml'][2]));

    }

    public function test_course_toc() {
        $generator = $this->getDataGenerator();

        // Create topics course with 10 sections and 1 module.
        $course = $generator->create_course([
            'shortname' => 'testlistlarge',
            'format' => 'topics',
            'numsections' => 10
        ], ['createsections' => true]);
        $page = $generator->create_module('page', array('course' => $course->id, 'name' => 'test page'));

        $toc = $this->courseservice->course_toc('testlistlarge');
        $this->assertTrue($toc->modules[0] instanceof theme_snap\renderables\course_toc_module);
        $this->assertTrue($toc->modules[0]->url === '#section-0&module-'.$page->cmid);
        $this->assertTrue($toc instanceof theme_snap\renderables\course_toc);
        $this->assertEquals(true, $toc->formatsupportstoc);
        $this->assertEquals('list-large', $toc->chapters->listlarge);
        $this->assertCount(11, $toc->chapters->chapters);

        // Create topics course with 9 sections.
        $generator->create_course([
            'shortname' => 'testlistsmall',
            'format' => 'topics',
            'numsections' => 9
        ], ['createsections' => true]);
        $toc = $this->courseservice->course_toc('testlistsmall');
        $this->assertNotEquals('list-large', $toc->chapters->listlarge);

        // Create social format course.
        $generator->create_course([
            'shortname' => 'socialcourse',
            'format' => 'social',
            'numsections' => 2
        ], ['createsections' => true]);
        $toc = $this->courseservice->course_toc('socialcourse');
        $this->assertFalse($toc->formatsupportstoc);
    }

    public function test_course_toc_chapters() {
        $generator = $this->getDataGenerator();

        // Create topics course
        $generator->create_course([
            'shortname' => 'testcourse',
            'format' => 'topics',
            'numsections' => 2
        ], ['createsections' => true]);
        $chapters = $this->courseservice->course_toc_chapters('testcourse');

        $this->assertCount(3, $chapters->chapters);
        $this->assertTrue($chapters->chapters[0] instanceof theme_snap\renderables\course_toc_chapter);
    }

    public function test_highlight_section() {
        $generator = $this->getDataGenerator();

        // Create topics course
        $generator->create_course([
            'shortname' => 'testcourse',
            'format' => 'topics',
            'numsections' => 5
        ], ['createsections' => true]);

        $this->setAdminUser();

        // Highlight the section.
        $highlight = $this->courseservice->highlight_section('testcourse', 3, true);
        $this->assertTrue(isset($highlight['actionmodel']));
        $this->assertTrue(isset($highlight['toc']));
        $actionmodel = $highlight['actionmodel'];
        $toc = $highlight['toc'];
        $this->assertTrue($actionmodel instanceof theme_snap\renderables\course_action_section_highlight);
        $this->assertTrue($toc instanceof theme_snap\renderables\course_toc);

        // Check that action model has toggled after highlight.
        $this->assertEquals('snap-highlight snap-marked', $actionmodel->class);
        $this->assertEquals('This topic is highlighted as the current topic', $actionmodel->title);
        $this->assertContains('marker=0', $actionmodel->url);

        // Unhiglight the section.
        $highlight = $this->courseservice->highlight_section('testcourse', 3, false);
        $actionmodel = $highlight['actionmodel'];
        $this->assertTrue($actionmodel instanceof theme_snap\renderables\course_action_section_highlight);

        // Check that action model now corresponds to unhighlighted state.
        $this->assertEquals('snap-highlight snap-marker', $actionmodel->class);
        $this->assertEquals('Highlight this topic as the current topic', $actionmodel->title);
        $this->assertContains('marker=3', $actionmodel->url);
    }

    public function test_set_section_visibility() {
        $generator = $this->getDataGenerator();

        // Create topics course
        $generator->create_course([
            'shortname' => 'testcourse',
            'format' => 'topics',
            'numsections' => 5
        ], ['createsections' => true]);

        $this->setAdminUser();

        // Hide the section.
        $visibility = $this->courseservice->set_section_visibility('testcourse', 3, false);
        $this->assertTrue(isset($visibility['actionmodel']));
        $this->assertTrue(isset($visibility['toc']));
        $actionmodel = $visibility['actionmodel'];
        $toc = $visibility['toc'];
        $this->assertTrue($actionmodel instanceof theme_snap\renderables\course_action_section_visibility);
        $this->assertTrue($toc instanceof theme_snap\renderables\course_toc);


        // Check that action model has toggled after section hidden.
        $this->assertEquals('snap-visibility snap-show', $actionmodel->class);
        $this->assertEquals('Show topic', $actionmodel->title);
        $this->assertContains('show=3', $actionmodel->url);

        // Unhide the section.
        $visibility = $this->courseservice->set_section_visibility('testcourse', 3, true);
        $actionmodel = $visibility['actionmodel'];
        $this->assertTrue($actionmodel instanceof theme_snap\renderables\course_action_section_visibility);

        // Check that action model now corresponds to unhighlighted state.
        $this->assertEquals('snap-visibility snap-hide', $actionmodel->class);
        $this->assertEquals('Hide topic', $actionmodel->title);
        $this->assertContains('hide=3', $actionmodel->url);
    }

    // Records for favorite courses should not exist when the user is deleted.

    public function test_user_deletion() {
        global $DB;

        $service = $this->courseservice;
        $service->setfavorite($this->courses[0]->shortname, true, $this->user1->id);
        $service->setfavorite($this->courses[1]->shortname, true, $this->user1->id);
        $favorites = $DB->get_records('theme_snap_course_favorites', array('userid'=>$this->user1->id));
        $this->assertNotEmpty($favorites);
        $userid = $this->user1->id;
        delete_user($this->user1);
        $favorites = $DB->get_records('theme_snap_course_favorites', array('userid'=>$userid));
        $this->assertEmpty($favorites);
    }

}
