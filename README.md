## Warning ⚠️

This project served it's purpose for rtCamp's own need. The project, by its nature, is not required anymore by rtCamp so it is not maintained anymore.

Your support requests and pull requests may get unanswered.

**☠️ Use at your own risk. ☠️**

## Requirements

PHP 7.1 or higher.

## Setup

Download dependencies via composer
```bash
composer install
```

Create `.env` file

```bash
cp .env.sample .env
```

Update `.env` with all details.

Note: Some endpoint requires admin/sudo token like getting snippets.

## Run 🚀

```bash
php main.php
```

##### Get user mapping file

This creates user mapping of GitLab and GitHub username's for and updates it's reference in comments, description and assignee accordingly.
The generated csv is used internally to update username references as mentioned above.

```bash
php main.php --user --create-mapped-csv
```

##### To migrate a repo

```bash
php main.php --migrate-repo --gitlab-group=test-github-import --gitlab-project-name=test-repo-1 --includes=all --force-assignee --github-name=test-repo-1 --yes
```

##### To migrate a group
```bash
php main.php --migrate-repo --gitlab-group=group-name --includes=all --use-repo-name-as-github-repo --yes
```
`--yes` will not prompt for migrating each repo.

##### Archive repos by namespace or group
```bash
php main.php --archive --gitlab-group=test-github-import
```

##### Archive a repo by project name
```bash
php main.php --archive --gitlab-group=test-github-import --gitlab-project-name=test-repo-1
```

##### To delete a repo

```bash
php main.php --delete --path=./delete-repos.csv --yes
```

| Namespace/Reponame                 |
| -----------------------------------|
| test-github-import/to-be-deleted-1 |
| test-github-import/to-be-deleted-2 |

##### To list repos with snippets
```bash
php main.php --get-snippet-info
```

##### Migrate all snippets in single repo.

Uses `SNIPPET_REPO_GIT_URL` in .env

Uses admin sudo endpoint to get all snippets from GitLab.

```bash
php main.php --migrate-snippets
```

#### To add team to GitHub groups
```bash
php main.php --add-team --team=rtMedia --keyword=rtmedia
```

#### List repo
```bash
php main.php --list-repo --export=csv/json
```

### Get gitlab statistics

Display in a table format on console

```bash
php stats.php
```

Export to CSV file

```bash
php stats.php > stats.csv
```


### Note
- By default all projects will be created with `private` access. To create a repo with `public` visibility, pass `--public` arg explicitly.
- If you want to import all project of a group do no specify gitlab-project-name.
- You can add `--use-repo-name-as-github-repo` which will not rename project as `{group-name}-{project-name}`.
- `--github-name` name still takes priority on 👆 if specified.


## Open issues / Doesn't handle.

- Doesn't Migrate Images/Attachment right now, uses Gitlab Image/Attachment URL in comment/description.
- Doesn't migrate Wiki.
- Doesn't handle PR and it's comment well.
- Doesn't Handle mapping of external users and blocked users of GitLab.
- Project settings and collaborators. (Partially handles invites when adding issue assignee if they are part of organization)

## API Warning ⚠️

This project uses GitHub's Preview API. It is undergoing changes so things might break anytime.

## License 🎓

MIT

## Contributors

Thanks goes to these wonderful people ([emoji key](https://github.com/all-contributors/all-contributors#emoji-key)):

<!-- ALL-CONTRIBUTORS-LIST:START - Do not remove or modify this section -->
<!-- prettier-ignore -->
| [<img src="https://avatars2.githubusercontent.com/u/4115?v=4" width="50px;" alt="Rahul Bansal"/><br /><sub><b>Rahul Bansal</b></sub>](https://github.com/rahul286)<br />[🤔](#ideas-rahul286 "Ideas, Planning, & Feedback") [📖](https://github.com/rtCamp/gitlab-2-github/commits?author=rahul286 "Documentation") [💻](https://github.com/rtCamp/gitlab-2-github/commits?author=rahul286 "Code") | [<img src="https://avatars1.githubusercontent.com/u/5015489?v=4" width="50px;" alt="Utkarsh Patel"/><br /><sub><b>Utkarsh Patel</b></sub>](https://github.com/PatelUtkarsh)<br />[🤔](#ideas-PatelUtkarsh "Ideas, Planning, & Feedback") [💻](https://github.com/rtCamp/gitlab-2-github/commits?author=PatelUtkarsh "Code") [📖](https://github.com/rtCamp/gitlab-2-github/commits?author=PatelUtkarsh "Documentation") [👀](#review-PatelUtkarsh "Reviewed Pull Requests") | [<img src="https://avatars3.githubusercontent.com/u/13589980?v=4" width="50px;" alt="Thrijith Thankachan"/><br /><sub><b>Thrijith Thankachan</b></sub>](https://github.com/thrijith)<br />[💻](https://github.com/rtCamp/gitlab-2-github/commits?author=thrijith "Code") [📖](https://github.com/rtCamp/gitlab-2-github/commits?author=thrijith "Documentation") | [<img src="https://avatars0.githubusercontent.com/u/11362577?v=4" width="50px;" alt="Vaishali"/><br /><sub><b>Vaishali</b></sub>](https://github.com/vaishaliagola27)<br />[💻](https://github.com/rtCamp/gitlab-2-github/commits?author=vaishaliagola27 "Code") [📖](https://github.com/rtCamp/gitlab-2-github/commits?author=vaishaliagola27 "Documentation") | [<img src="https://avatars3.githubusercontent.com/u/9035925?v=4" width="50px;" alt="Vishal Kakadiya"/><br /><sub><b>Vishal Kakadiya</b></sub>](https://github.com/vishalkakadiya)<br />[💻](https://github.com/rtCamp/gitlab-2-github/commits?author=vishalkakadiya "Code") [📖](https://github.com/rtCamp/gitlab-2-github/commits?author=vishalkakadiya "Documentation") |
| :---: | :---: | :---: | :---: | :---: |
<!-- ALL-CONTRIBUTORS-LIST:END -->
