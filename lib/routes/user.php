<?php

require_once 'lib/db.php';

$checkAuth = function($role = 1) {
  return function () use ($role) {
      $app = \Slim\Slim::getInstance();
      $user = $app->view->get('user');
      $data = array(
        'success' => false
      );

      if($user == false) {
        $data['error'] = 'You must be logged in to perform that action.';
      } else if ($user->role > $role) {
        $data['error'] = 'You have insufficient privileges to perform that action.';
      }

      if($user == false || $user->role > $role) {
        $app->response->headers->set('Content-Type', 'application/json');
        $app->response->setBody(json_encode($data));
        $app->stop();
      }
  };
};

$app->get(
    '/',
    function() use($app, $db) {
      $app->render('index.html', array(
        'elections' => $db->getElection()
      ));
    }
)->name('homepage');

$app->get(
  '/campaigns(/)',
  function() use($app, $db) {
    $app->render('campaigns.html', array(
      'elections' => $db->getElection()
    ));
  }
)->name('campaigns');

$app->get(
  '/campaign/:id(/)',
  function($id) use ($app, $db) {
    $user = $app->view->get('user');
    $elec = $db->getElection($id);

    if (!$elec->approved && (!isset($user) || !$user->isAdmin())) {
      $app->flash('error', 'You have insufficient privileges to access that election.');
      $app->redirect($app->urlFor('homepage'));
    } else {
      $app->render('campaign.html', array(
        'election' => $elec,
        'myvote' => isset($user) ? $db->getVotes($id, $user->id) : null,
        'votes' => isset($user) ? ($user->isAdmin() ? $db->getVotes($id) : null) : null
      ));
    }
  }
)->name('election');

$app->get(
  '/campaign/:id/results',
  function($id) use ($app, $db) {
    $user = $app->view->get('user');
    $elec = $db->getElection($id);

    if (!isset($user)) {
      $app->flash('error', 'You must be logged in to view election results.');
      $app->redirect($app->urlFor('homepage'));
    } else if (empty($elec)) {
      $app->flash('error', 'Results for an election with that ID do not exist.');
      $app->redirect($app->urlFor('homepage'));
    } else {
      if ((!$elec->approved || ($elec->getData('certified') != true)) && !$user->isAdmin()) {
        $app->flash('error', 'You do not have permission to access those results.');
        $app->redirect($app->urlFor('homepage'));
      } else {
        $app->render('results.html', array(
          'election' => $elec,
          'votes' => $db->getVotes($id),
          'users' => $user->IsAdmin() ? $db->getUser() : null
        ));
      }
    }
  }
)->name('electionResults');

$app->post(
  '/campaign/:id/vote',
  $checkAuth(3),
  function($id) use ($app, $db) {
    $app->response->headers->set('Content-Type', 'application/json');
    $elec = $db->getElection($id);
    $user = $app->view->get('user');
    $prevVote = $db->getVotes($id, $user->id);

    if ($elec == null || $elec == false) {
      echo json_encode(array(
        'success' => false,
        'error' => 'An election with that ID does not exist.'
      ));
      return;
    }

    $banKey = array_search(
      $user->id,
      $elec->getData('bannedUsers') == null ? array() : $elec->getData('bannedUsers')
    );

    if ($user->getData('banned') == true || $banKey != false || $elec->getData('certified') == true) {
      echo json_encode(array(
        'success' => false,
        'error' => 'You do not have permission to cast a vote in this election.'
      ));
      return;
    }

    if (!empty($prevVote)) {
      echo json_encode(array(
        'success' => false,
        'error' => 'You cannot submit more than one vote.'
      ));
      return;
    }

    $races = $elec->getRaces();
    $data = json_decode($app->request->getBody());
    foreach($data as $item) {
      foreach($item as $k => $v) {
        $keys = explode(':', $k);
        $raceID = intval($keys[1], 10);
        $race = isset($elec->getRaces()[$raceID]) ? $elec->getRaces()[$raceID] : null;
        $canID = -1;

        if ($v == 'candidate:writein' && $race->allowWriteIn == true) {
          continue;
        }

        if(sizeof($keys) == 2) {
          $vals = explode(':', $v);
          $canID = intval($vals[1], 10);
        }

        $vote = new Vote((object) array(
          'user' => $user->id,
          'election' => $elec->id,
          'race' => $raceID,
          'candidate' => $canID
        ));

        if (sizeof($keys) == 3 && $keys[2] == 'writein' && $race->allowWriteIn == true) {
          $vote->setData('writein', $v);
        }

        if ($race == null || $keys[0] != 'race' || $raceID > sizeof($races)-1 || $vote->candidate > sizeof($races[$raceID]->candidates) || ((!isset($race->allowWriteIn) || $race->allowWriteIn != true) && sizeof($keys) == 3)) {
          echo json_encode(array(
            'success' => false,
            'error' => 'There was an error submitting your vote. Please refresh and/or try again later.'
          ));
          return;
        }

        $usrTags = $user->getData('tags') == null ? array() : $user->getData('tags');
        $restrictions = isset($race->restrictions) ? $race->restrictions : array();
        if (!empty($restrictions)) {
          $banKey = array_intersect($usrTags, $restrictions);

          if ($banKey != false) {
            echo json_encode(array(
              'success' => false,
              'error' => 'You do not have permission to cast a vote in this election.'
            ));
            return;
          }
        }
        $vote->save($db);
      }
    }

    echo json_encode(array(
      'success' => true,
      'data' => $data,
      'vote' => $vote
    ));
  }
)->name('voteFor');

$app->get(
  '/results(/)',
  function() use($app, $db) {
    $app->render('resultsListing.html', array(
      'elections' => $db->getElection()
    ));
  }
)->name('results');

$app->get(
  '/about(/)',
  function() use($app) {
    $app->render('about.html');
  }
)->name('about');

$app->get(
  '/dashboard(/)',
  function() use($app, $db) {
    $user = $app->view->get('user');
    if ($user == false) {
      $app->flash('error', 'You must be logged in to access that page.');
      $app->redirect($app->urlFor('homepage'));
    } else {
      $data['elections'] = $db->getElection();
      if ($user->isAdmin()) {
        $data['users'] = $db->getUser();
      }
      $data['votes'] = $db->getVotes(-1);
      $data['myvotes'] = $db->getVotes(-1, $user->id);
      $app->render('dashboard.html', $data);
    }
  }
)->name('dashboard');

$app->get(
  '/dashboard/create-ballot',
  function() use($app) {
    $user = $app->view->get('user');
    if (!isset($user)) {
      $app->flash('error', 'You must be logged in to access that page.');
      $app->redirect($app->urlFor('homepage'));
    } else if ($user->isEC()) {
      $app->render('create-ballot.html');
    } else {
      $app->flash('error', 'You do not have permission to access that page.');
      $app->redirect($app->urlFor('homepage'));
    }
  }
)->name('create.ballot');

$app->post(
  '/dashboard/create-ballot',
  function() use ($app, $db) {
    $user = $app->view->get('user');
    $data = array();
    $app->response->headers->set('Content-Type', 'application/json');
    if(!isset($user) || !$user->isEC()) {
      $data['success'] = false;
      $data['message'] = 'You do not have permission to perform that action.';
    } else {
      $postData = json_decode($app->request->getBody());
      $election = new Election($postData);
      $election->save($db);
      if(isset($election->id)) {
        $data['success'] = true;
        $data['id'] = $election->id;
        $data['message'] = 'Successfully created ballot "'.$election->name.'"';
      }
    }
    $app->response->setBody(json_encode($data));
  }
);
