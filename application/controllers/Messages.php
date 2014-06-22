<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Messages Controller
 *
 * This class handles sending, reading, displaying of messages.
 *
 * @package        BitWasp
 * @subpackage    Controllers
 * @category    Messages
 * @author        BitWasp
 *
 */
class Messages extends MY_Controller
{

    /**
     * Constructor
     *
     * Load libs/models, and direct users to enter their pin in required.
     *
     * @access    public
     * @see        Models/Currencies_Model
     * @see        Libraries/Bw_Messages
     * @see        Libraries/OpenSSL
     */
    public function __construct()
    {
        parent::__construct();
        $this->load->model('messages_model');
        $this->load->library('bw_messages');
        $this->load->library('openssl');
    }

    /**
     * Read a Message.
     * URI: /message/$hash
     *
     * @access    public
     * @see        Models/Messages_Model
     * @see        Libraries/Bw_Messages
     *
     * @param        string $hash
     */
    public function read($hash)
    {
        // Redirect if the message does not exist.
        $message = $this->messages_model->get($hash);
        if ($message == FALSE)
            redirect('inbox');

        // Pass the message through a preparation function.
        $message = $this->bw_messages->prepare_output(array($message));
        $data['message'] = $message[0];
        $data['title'] = $data['message']['subject'];
        $data['page'] = 'messages/read';

        // Mark message 'read' if it's currently unread.
        if ($data['message']['viewed'] == '0')
            $this->messages_model->set_viewed($data['message']['id']);

        // If 'remove on read' is set, delete the message now that it's been displayed.
        if ($data['message']['remove_on_read'] == '1')
            $this->messages_model->delete($data['message']['id']);

        $this->_render($data['page'], $data);
    }


    /**
     * Inbox
     *
     * Load a Users Inbox.
     * URI: /listings/edit/$hash
     *
     */
    public function inbox()
    {
        // Load inbox and pass through preparation function.
        $messages = $this->messages_model->inbox();
        $data['messages'] = $this->bw_messages->prepare_output($messages);
        $data['page'] = 'messages/inbox';
        $data['title'] = 'Inbox';
        $this->_render($data['page'], $data);
    }

    /**
     * Delete a specified message, or all of them if $hash=='all'
     * URI: /messages/delete/$hash
     *
     * @access    public
     * @see        Libraries/Bw_Messages
     * @see        Models/Messages_Model
     *
     * @param    string $hash
     * @return    void
     */
    public function delete($hash)
    {
        if ($hash == 'all') {
            if ($this->messages_model->delete_all() == TRUE) {
                $this->session->set_flashdata('msgs_delete', 'true');
                redirect('message/deleted');
            } else {
                $data['returnMessage'] = 'Error deleting messages, try again later.';
            }
        } else {
            $get = $this->messages_model->get($hash);
            if ($get !== FALSE) {
                if ($this->messages_model->delete($get['id']) == TRUE) {
                    $this->session->set_flashdata('msg_delete', 'true');
                    redirect('message/deleted');
                } else {
                    $data['returnMessage'] = 'Error deleting message, try again later.';
                }
            } else {
                redirect('inbox');
            }
        }

        // Reload inbox with error message.
        $data['title'] = 'Inbox';
        $data['page'] = 'messages/inbox';
        $messages = $this->messages_model->inbox();
        $data['messages'] = $this->bw_messages->prepare_output($messages);

        $this->_render($data['page'], $data);
    }

    /**
     * Page to handle deleted messages, to avoid user resubmitting URI's.
     * URI: /messages/deleted
     *
     * @access    public
     * @see        Libraries/Bw_Messages
     * @see        Models/Messages_Model
     *
     * @return    void
     */
    public function deleted()
    {

        $data['title'] = 'Inbox';
        $data['page'] = 'messages/inbox';
        $messages = $this->messages_model->inbox();
        $data['messages'] = $this->bw_messages->prepare_output($messages);

        if ($this->session->flashdata('msg_delete') == TRUE) {
            $data['returnMessage'] = 'Message has been deleted';
        } else if ($this->session->flashdata('msgs_delete') == TRUE) {
            $data['returnMessage'] = 'All messages have been deleted.';
        } else {
            redirect('inbox');
        }

        $this->_render($data['page'], $data);
    }

    /**
     * Send a message. May be responding to a user, or message, as
     * specified by $identifier. $identifier may be unset.
     * URI: /messages/send/$identifier
     *
     * @access    public
     * @see        Libraries/Form_Validation
     * @see        Libraries/Bw_Messages
     * @see        Models/Messages_Model
     *
     * @param    string $identifier
     * @return    void
     */
    public function send($identifier = NULL)
    {

        $this->load->library('form_validation');

        $data['to_name'] = '';
        $data['subject'] = '';
        $data['action_uri'] = uri_string();
        $data['public_key'] = '';

        $reply_info = $this->messages_model->reply_info($identifier);

        // If the specified $identifier is meaningless, redirect to regular form.
        if ($reply_info == NULL && $identifier !== NULL)
            redirect('message/send');

        // Parse information from the reply_info array.
        if (is_array($reply_info)) {
            $data['to_name'] = $reply_info['to_name'];
            $data['subject'] = $reply_info['subject'];

            // If the public key is specified, load it's information.
            if (isset($reply_info['public_key'])) {
                $data['public_key'] = $reply_info['public_key'];
                $data['fingerprint'] = $reply_info['fingerprint'];
            }
        }

        // If the public key is set, load the JS for clientside PGP.
        if ($data['public_key'] !== '') {
            $data['header_meta'] = $this->load->view('messages/encryption_header', NULL, true);
            $data['returnMessage'] = 'This message will be encrypted automatically if you have javascript enabled.<br />';
        }

        if ($this->form_validation->run('send_message') == TRUE) {
            // Form validation was successful, prepare the message.
            $data['from'] = $this->current_user->user_id;
            $message = $this->bw_messages->prepare_input($data);
            if ($this->messages_model->send($message)) {
                $this->session->set_flashdata('returnMessage', json_encode(array('message'=>'Your message has been sent!')));
                redirect('inbox');
            }
        }

        $data['page'] = 'messages/send';
        $data['title'] = 'Send Message';

        $this->_render($data['page'], $data);
    }

    /**
     * Enter PIN.
     *
     * Prompt for a users message PIN if it's not set. Determines if the
     * PIN is correct by encrypting a challenge with the users public key,
     * and tries to unlock the challenge again using the users private key
     * and the generated password for that key.
     * URI: /messages/pin
     *
     * @access    public
     * @see        Libraries/Form_Validation
     * @see        Libraries/Bw_Messages
     * @see        Models/Messages_Model
     *
     * @param    string
     * @return    void
     */
    public function enter_pin()
    {
        $this->load->model('users_model');
        $this->load->library('form_validation');

        $this->load->helper(array('form'));

        if ($this->form_validation->run('message_pin_form') == TRUE) {
            // Load the users salt, public key, and private key.
            $user = $this->users_model->message_data(array('user_hash' => $this->current_user->user_hash));
            $message_password = $this->general->password($this->input->post('pin'), $user['salt']);

            // Encrypt with public key, attempt to decrypt with private key & password.
            $solution = $this->general->generate_salt();
            $challenge = $this->openssl->encrypt($solution, $user['public_key']);
            $answer = $this->openssl->decrypt($challenge, $user['private_key'], $message_password);
            if ($answer == $solution) {
                $this->current_user->set_message_password($message_password);
                unset($message_password);
                $redirect_url = $this->session->userdata('before_msg_pin');
                $this->session->unset_userdata('before_msg_pin');

                redirect($redirect_url);
            } else {
                $data['returnMessage'] = 'The PIN you entered was incorrect. Please try again';
            }
        }
        $data['title'] = 'Message PIN';
        $data['page'] = 'messages/pin';

        $this->_render($data['page'], $data);
    }

};

/* End of file: Messages.php */
/* Location: application/controllers/Messages.php */
